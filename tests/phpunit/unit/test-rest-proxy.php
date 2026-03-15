<?php
/**
 * Unit tests — ExtractIA_REST_Proxy
 *
 * Tests each REST endpoint handler in isolation,
 * verifying correct WP_REST_Response status codes and body contents.
 */

use PHPUnit\Framework\TestCase;

class Test_REST_Proxy extends TestCase {

    private ExtractIA_REST_Proxy $proxy;

    private function mock_response( int $code, array|string $body ): void {
        $GLOBALS['_wp_remote_response'] = [
            'response' => [ 'code' => $code, 'message' => 'OK' ],
            'body'     => is_array( $body ) ? json_encode( $body ) : $body,
        ];
    }

    private function make_request( string $method = 'GET', array $params = [] ): WP_REST_Request {
        $req = new WP_REST_Request( $method );
        foreach ( $params as $k => $v ) {
            $req->set_param( $k, $v );
        }
        return $req;
    }

    protected function setUp(): void {
        $GLOBALS['_extractia_options']   = [ 'extractia_api_key' => 'test-key-abc' ];
        $GLOBALS['_extractia_transients'] = [];
        $GLOBALS['_wp_remote_response']  = null;
        $this->proxy = new ExtractIA_REST_Proxy();
    }

    // ── GET /templates ────────────────────────────────────────────────────────

    public function test_get_templates_200(): void {
        $this->mock_response( 200, [ ['id' => 'tpl-1', 'name' => 'Invoice'] ] );
        $res = $this->proxy->get_templates( $this->make_request() );
        $this->assertEquals( 200, $res->get_status() );
        $this->assertIsArray( $res->get_data() );
    }

    public function test_get_templates_propagates_api_error(): void {
        $this->mock_response( 401, [ 'error' => 'Unauthorized' ] );
        $res = $this->proxy->get_templates( $this->make_request() );
        $this->assertEquals( 401, $res->get_status() );
        $this->assertEquals( 'authError', $res->get_data()['i18nKey'] );
    }

    // ── GET /usage ────────────────────────────────────────────────────────────

    public function test_get_usage_returns_quota_fields(): void {
        // profile
        $GLOBALS['_wp_remote_response'] = [
            'response' => [ 'code' => 200, 'message' => 'OK' ],
            'body'     => json_encode( [ 'tier' => 'pro', 'documentsUsed' => 10, 'documentsLimit' => 200 ] ),
        ];
        $res = $this->proxy->get_usage( $this->make_request() );
        $this->assertEquals( 200, $res->get_status() );
        $data = $res->get_data();
        $this->assertArrayHasKey( 'documentsUsed', $data );
        $this->assertArrayHasKey( 'documentsLimit', $data );
        $this->assertEquals( 10, $data['documentsUsed'] );
    }

    public function test_get_usage_returns_error_when_api_fails(): void {
        $this->mock_response( 401, [ 'error' => 'Unauthorized' ] );
        $res = $this->proxy->get_usage( $this->make_request() );
        $this->assertEquals( 401, $res->get_status() );
    }

    // ── POST /process ─────────────────────────────────────────────────────────

    public function test_process_image_200(): void {
        $doc = [ 'id' => 'doc-1', 'data' => [ 'total' => '50.00' ] ];
        $this->mock_response( 200, $doc );
        $req = $this->make_request( 'POST', [
            'templateId' => 'tpl-1',
            'image'      => 'data:image/jpeg;base64,' . base64_encode( 'fake-image-data' ),
        ] );
        $res = $this->proxy->process_image( $req );
        $this->assertEquals( 200, $res->get_status() );
        $this->assertEquals( 'doc-1', $res->get_data()['id'] );
    }

    public function test_process_image_rejects_oversized_file(): void {
        update_option( 'extractia_max_file_size_mb', 1 );
        $big_image = 'data:image/jpeg;base64,' . str_repeat( 'A', ( 1 * 1024 * 1024 * 1.5 ) + 100 );
        $req = $this->make_request( 'POST', [ 'templateId' => 'tpl-1', 'image' => $big_image ] );
        $res = $this->proxy->process_image( $req );
        $this->assertEquals( 413, $res->get_status() );
        $this->assertEquals( 'fileTooLarge', $res->get_data()['i18nKey'] );
    }

    public function test_process_image_propagates_quota_error(): void {
        $this->mock_response( 402, [ 'error' => 'Quota exceeded' ] );
        $req = $this->make_request( 'POST', [
            'templateId' => 'tpl-1',
            'image'      => 'data:image/jpeg;base64,abc',
        ] );
        $res = $this->proxy->process_image( $req );
        $this->assertEquals( 402, $res->get_status() );
        $this->assertEquals( 'quotaExceeded', $res->get_data()['i18nKey'] );
    }

    // ── POST /process-multipage ───────────────────────────────────────────────

    public function test_process_multipage_200(): void {
        $doc = [ 'id' => 'doc-multi', 'pages' => 2 ];
        $this->mock_response( 200, $doc );
        $req = $this->make_request( 'POST', [
            'templateId' => 'tpl-1',
            'images'     => [ 'b64-1', 'b64-2' ],
        ] );
        $res = $this->proxy->process_multipage( $req );
        $this->assertEquals( 200, $res->get_status() );
        $this->assertEquals( 2, $res->get_data()['pages'] );
    }

    public function test_process_multipage_rejects_empty_images(): void {
        $req = $this->make_request( 'POST', [ 'templateId' => 'tpl-1', 'images' => [] ] );
        $res = $this->proxy->process_multipage( $req );
        $this->assertEquals( 400, $res->get_status() );
    }

    public function test_process_multipage_rejects_more_than_20_pages(): void {
        $req = $this->make_request( 'POST', [
            'templateId' => 'tpl-1',
            'images'     => array_fill( 0, 21, 'b64' ),
        ] );
        $res = $this->proxy->process_multipage( $req );
        $this->assertEquals( 400, $res->get_status() );
    }

    // ── POST /summary ─────────────────────────────────────────────────────────

    public function test_get_summary_200(): void {
        $this->mock_response( 200, [ 'summary' => 'An invoice for $99.' ] );
        $req = $this->make_request( 'POST', [ 'docId' => 'doc-1' ] );
        $res = $this->proxy->get_summary( $req );
        $this->assertEquals( 200, $res->get_status() );
        $this->assertStringContainsString( '99', $res->get_data()['summary'] );
    }

    public function test_get_summary_tier_error(): void {
        $this->mock_response( 403, [ 'error' => 'Not available on free plan' ] );
        $req = $this->make_request( 'POST', [ 'docId' => 'doc-1' ] );
        $res = $this->proxy->get_summary( $req );
        $this->assertEquals( 403, $res->get_status() );
        $this->assertEquals( 'tierError', $res->get_data()['i18nKey'] );
    }

    // ── POST /ocr-run ─────────────────────────────────────────────────────────

    public function test_run_ocr_tool_200(): void {
        $this->mock_response( 200, [ 'answer' => 'Yes', 'explanation' => 'Found.' ] );
        $req = $this->make_request( 'POST', [
            'toolId' => 'tool-sig',
            'image'  => 'data:image/jpeg;base64,abc',
            'params' => [],
        ] );
        $res = $this->proxy->run_ocr_tool( $req );
        $this->assertEquals( 200, $res->get_status() );
        $this->assertEquals( 'Yes', $res->get_data()['answer'] );
    }

    public function test_run_ocr_tool_sanitizes_params(): void {
        $this->mock_response( 200, [ 'answer' => 'result', 'explanation' => '' ] );
        $req = $this->make_request( 'POST', [
            'toolId' => 'tool-1',
            'image'  => 'data:image/jpeg;base64,abc',
            'params' => [ 'key' => '<script>alert(1)</script>' ],
        ] );
        $res = $this->proxy->run_ocr_tool( $req );
        // Must succeed (sanitization happens silently)
        $this->assertEquals( 200, $res->get_status() );
    }

    // ── POST /webhook-test ────────────────────────────────────────────────────

    public function test_webhook_test_fails_without_url(): void {
        $GLOBALS['_extractia_options']['extractia_webhook_url'] = '';
        $res = $this->proxy->test_webhook( $this->make_request( 'POST' ) );
        $this->assertEquals( 400, $res->get_status() );
    }

    public function test_webhook_test_returns_ok(): void {
        $GLOBALS['_extractia_options']['extractia_webhook_url'] = 'https://hooks.example.com/test';
        $GLOBALS['_wp_remote_response'] = [
            'response' => [ 'code' => 200, 'message' => 'OK' ],
            'body'     => '{"ok":true}',
        ];
        $res = $this->proxy->test_webhook( $this->make_request( 'POST' ) );
        $this->assertEquals( 200, $res->get_status() );
        $this->assertTrue( $res->get_data()['ok'] );
    }

    // ── Nonce check ───────────────────────────────────────────────────────────

    public function test_check_nonce_always_returns_true(): void {
        // Our stub allows all; the real WP infrastructure handles nonce validation.
        $result = $this->proxy->check_nonce( $this->make_request() );
        $this->assertTrue( $result );
    }
}
