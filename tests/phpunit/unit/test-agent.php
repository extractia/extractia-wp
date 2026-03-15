<?php
/**
 * Unit tests — ExtractIA_Agent
 *
 * Tests agent creation, step chaining, config validation,
 * execution via mocked API, and condition branching.
 */

use PHPUnit\Framework\TestCase;

class Test_Agent extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_extractia_options']   = [ 'extractia_api_key' => 'test-key' ];
        $GLOBALS['_extractia_transients'] = [];
        $GLOBALS['_wp_remote_response']  = null;
    }

    private function mock( int $code, array $body ): void {
        $GLOBALS['_wp_remote_response'] = [
            'response' => [ 'code' => $code, 'message' => 'OK' ],
            'body'     => json_encode( $body ),
        ];
    }

    // ── Registry ──────────────────────────────────────────────────────────────

    public function test_register_and_get_agent(): void {
        ExtractIA_Agent::register( 'invoice-flow', [
            'name'  => 'Invoice Flow',
            'steps' => [
                [ 'type' => 'extract', 'templateId' => 'tpl-invoice' ],
            ],
        ] );
        $agent = ExtractIA_Agent::get( 'invoice-flow' );
        $this->assertEquals( 'Invoice Flow', $agent['name'] );
        $this->assertCount( 1, $agent['steps'] );
    }

    public function test_get_unknown_agent_returns_null(): void {
        $this->assertNull( ExtractIA_Agent::get( 'non-existent-xyz' ) );
    }

    public function test_get_all_agents_returns_array(): void {
        ExtractIA_Agent::register( 'agent-a', [ 'name' => 'A', 'steps' => [] ] );
        ExtractIA_Agent::register( 'agent-b', [ 'name' => 'B', 'steps' => [] ] );
        $all = ExtractIA_Agent::get_all();
        $this->assertIsArray( $all );
        $this->assertArrayHasKey( 'agent-a', $all );
        $this->assertArrayHasKey( 'agent-b', $all );
    }

    // ── Config validation ─────────────────────────────────────────────────────

    public function test_validate_accepts_valid_config(): void {
        $config = [
            'name'  => 'My Agent',
            'steps' => [
                [ 'type' => 'extract', 'templateId' => 'tpl-1' ],
                [ 'type' => 'ocr_tool', 'toolId' => 'tool-1' ],
            ],
        ];
        $errors = ExtractIA_Agent::validate_config( $config );
        $this->assertEmpty( $errors );
    }

    public function test_validate_rejects_missing_name(): void {
        $errors = ExtractIA_Agent::validate_config( [ 'steps' => [] ] );
        $this->assertContains( 'name_required', $errors );
    }

    public function test_validate_rejects_empty_steps(): void {
        $errors = ExtractIA_Agent::validate_config( [ 'name' => 'Test', 'steps' => [] ] );
        $this->assertContains( 'steps_required', $errors );
    }

    public function test_validate_rejects_extract_step_without_template(): void {
        $errors = ExtractIA_Agent::validate_config( [
            'name'  => 'Test',
            'steps' => [ [ 'type' => 'extract' ] ],
        ] );
        $this->assertContains( 'step_0_templateId_required', $errors );
    }

    public function test_validate_rejects_ocr_tool_step_without_tool_id(): void {
        $errors = ExtractIA_Agent::validate_config( [
            'name'  => 'Test',
            'steps' => [ [ 'type' => 'ocr_tool' ] ],
        ] );
        $this->assertContains( 'step_0_toolId_required', $errors );
    }

    public function test_validate_rejects_unknown_step_type(): void {
        $errors = ExtractIA_Agent::validate_config( [
            'name'  => 'Test',
            'steps' => [ [ 'type' => 'fly_to_moon' ] ],
        ] );
        $this->assertContains( 'step_0_invalid_type', $errors );
    }

    // ── Execution — extract step ──────────────────────────────────────────────

    public function test_run_extract_step_succeeds(): void {
        $this->mock( 200, [ 'id' => 'doc-1', 'data' => [ 'total' => '100.00' ] ] );

        $agent = new ExtractIA_Agent( [
            'name'  => 'Simple Extract',
            'steps' => [ [ 'type' => 'extract', 'templateId' => 'tpl-1' ] ],
        ] );

        $result = $agent->run( 'data:image/jpeg;base64,abc' );
        $this->assertIsArray( $result );
        $this->assertEquals( 'doc-1', $result['lastDoc']['id'] );
        $this->assertCount( 1, $result['steps'] );
        $this->assertEquals( 'done', $result['steps'][0]['status'] );
    }

    public function test_run_extract_step_fails_on_api_error(): void {
        $this->mock( 402, [ 'error' => 'Quota exceeded' ] );

        $agent = new ExtractIA_Agent( [
            'name'  => 'Quota Test',
            'steps' => [ [ 'type' => 'extract', 'templateId' => 'tpl-1' ] ],
        ] );

        $result = $agent->run( 'data:image/jpeg;base64,abc' );
        $this->assertEquals( 'error', $result['steps'][0]['status'] );
        $this->assertEquals( 'quotaExceeded', $result['steps'][0]['i18nKey'] );
    }

    // ── Execution — ocr_tool step ─────────────────────────────────────────────

    public function test_run_ocr_tool_step_succeeds(): void {
        // First call: extract; second call: ocr_tool
        $call = 0;
        $GLOBALS['_wp_remote_response'] = null;  // will be set per-call via override below

        // We'll just run a single ocr_tool step (no preceding extract needed):
        $this->mock( 200, [ 'answer' => 'No', 'explanation' => 'No signature found.' ] );

        $agent = new ExtractIA_Agent( [
            'name'  => 'Sig Check',
            'steps' => [ [ 'type' => 'ocr_tool', 'toolId' => 'tool-sig' ] ],
        ] );

        $result = $agent->run( 'data:image/jpeg;base64,abc' );
        $this->assertEquals( 'No', $result['steps'][0]['answer'] );
        $this->assertEquals( 'done', $result['steps'][0]['status'] );
    }

    // ── Condition step ────────────────────────────────────────────────────────

    public function test_condition_step_passes_when_field_matches(): void {
        $agent = new ExtractIA_Agent( [
            'name'  => 'Condition Test',
            'steps' => [
                [
                    'type'      => 'condition',
                    'field'     => 'status',
                    'operator'  => 'equals',
                    'value'     => 'approved',
                    'onTrue'    => 'continue',
                    'onFalse'   => 'stop',
                ],
            ],
        ] );

        // Inject extracted context so condition has data to inspect
        $result = $agent->run( 'data:image/jpeg;base64,abc', [ 'context' => [ 'status' => 'approved' ] ] );
        $this->assertEquals( 'continue', $result['steps'][0]['outcome'] );
    }

    public function test_condition_step_stops_when_field_not_matches(): void {
        $agent = new ExtractIA_Agent( [
            'name'  => 'Condition Stop',
            'steps' => [
                [
                    'type'     => 'condition',
                    'field'    => 'status',
                    'operator' => 'equals',
                    'value'    => 'approved',
                    'onTrue'   => 'continue',
                    'onFalse'  => 'stop',
                ],
            ],
        ] );

        $result = $agent->run( 'data:image/jpeg;base64,abc', [ 'context' => [ 'status' => 'rejected' ] ] );
        $this->assertEquals( 'stop', $result['steps'][0]['outcome'] );
        $this->assertEquals( 'stopped', $result['finalStatus'] );
    }

    // ── Webhook step ──────────────────────────────────────────────────────────

    public function test_webhook_step_fires_nonblocking(): void {
        $this->mock( 200, [] );

        $agent = new ExtractIA_Agent( [
            'name'  => 'Webhook Test',
            'steps' => [
                [
                    'type' => 'webhook',
                    'url'  => 'https://hooks.example.com/agent',
                ],
            ],
        ] );

        $result = $agent->run( 'data:image/jpeg;base64,abc' );
        $this->assertEquals( 'done', $result['steps'][0]['status'] );
    }
}
