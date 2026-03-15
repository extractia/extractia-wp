<?php
/**
 * Unit tests — ExtractIA_API_Client
 *
 * Tests HTTP error handling, response parsing, transient caching,
 * and the i18n_key_for_status helper.
 */

use PHPUnit\Framework\TestCase;

class Test_API_Client extends TestCase {

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function mock_response( int $code, array|string $body ): array {
        return [
            'response' => [ 'code' => $code, 'message' => 'OK' ],
            'body'     => is_array( $body ) ? json_encode( $body ) : $body,
        ];
    }

    private function set_response( int $code, array|string $body ): void {
        $GLOBALS['_wp_remote_response'] = $this->mock_response( $code, $body );
    }

    protected function setUp(): void {
        $GLOBALS['_extractia_options']   = [ 'extractia_api_key' => 'test-key-abc' ];
        $GLOBALS['_extractia_transients'] = [];
        $GLOBALS['_wp_remote_response']  = null;
    }

    // ── API key ───────────────────────────────────────────────────────────────

    public function test_api_client_reads_api_key(): void {
        $client = new ExtractIA_API_Client();
        // Indirect: a successful profile call means the key was included in headers.
        $this->set_response( 200, [ 'tier' => 'pro', 'documentsUsed' => 5, 'documentsLimit' => 100 ] );
        $result = $client->get_profile();
        $this->assertIsArray( $result );
        $this->assertEquals( 'pro', $result['tier'] );
    }

    public function test_api_client_returns_wp_error_when_key_missing(): void {
        $GLOBALS['_extractia_options'] = [];   // no key
        $client = new ExtractIA_API_Client();
        $result = $client->get_profile();
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertEquals( 'extractia_no_key', $result->get_error_code() );
    }

    // ── HTTP error handling ───────────────────────────────────────────────────

    public function test_returns_wp_error_on_401(): void {
        $client = new ExtractIA_API_Client();
        $this->set_response( 401, [ 'error' => 'Unauthorized' ] );
        $result = $client->get_templates();
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertEquals( 401, $result->get_error_data()['status'] );
    }

    public function test_returns_wp_error_on_402(): void {
        $client = new ExtractIA_API_Client();
        $this->set_response( 402, [ 'error' => 'Quota exceeded' ] );
        $result = $client->process_image( 'tpl-1', 'data:image/jpeg;base64,abc' );
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertEquals( 402, $result->get_error_data()['status'] );
    }

    public function test_returns_wp_error_on_429(): void {
        $client = new ExtractIA_API_Client();
        $this->set_response( 429, [ 'error' => 'Rate limited' ] );
        $result = $client->process_image( 'tpl-1', 'data:image/jpeg;base64,abc' );
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertEquals( 429, $result->get_error_data()['status'] );
    }

    public function test_returns_wp_error_on_network_error(): void {
        $GLOBALS['_wp_remote_response'] = new WP_Error( 'http_request_failed', 'Could not connect' );
        $client = new ExtractIA_API_Client();
        $result = $client->get_templates();
        $this->assertInstanceOf( WP_Error::class, $result );
    }

    public function test_returns_wp_error_on_invalid_json(): void {
        $GLOBALS['_wp_remote_response'] = [
            'response' => [ 'code' => 200, 'message' => 'OK' ],
            'body'     => 'not-json{{{{',
        ];
        $client = new ExtractIA_API_Client();
        $result = $client->get_templates();
        $this->assertInstanceOf( WP_Error::class, $result );
    }

    // ── Successful responses ──────────────────────────────────────────────────

    public function test_get_templates_returns_array(): void {
        $templates = [ ['id' => 'tpl-1', 'name' => 'Invoice'], ['id' => 'tpl-2', 'name' => 'Receipt'] ];
        $this->set_response( 200, $templates );
        $client = new ExtractIA_API_Client();
        $result = $client->get_templates();
        $this->assertIsArray( $result );
        $this->assertCount( 2, $result );
        $this->assertEquals( 'Invoice', $result[0]['name'] );
    }

    public function test_process_image_returns_document(): void {
        $doc = [ 'id' => 'doc-abc', 'data' => [ 'total' => '99.90', 'vendor' => 'ACME' ] ];
        $this->set_response( 200, $doc );
        $client = new ExtractIA_API_Client();
        $result = $client->process_image( 'tpl-1', 'data:image/jpeg;base64,/9j/aa==' );
        $this->assertIsArray( $result );
        $this->assertEquals( 'doc-abc', $result['id'] );
        $this->assertEquals( '99.90', $result['data']['total'] );
    }

    public function test_process_multipage_returns_document(): void {
        $doc = [ 'id' => 'doc-multi', 'pages' => 3, 'data' => [ 'total' => '200.00' ] ];
        $this->set_response( 200, $doc );
        $client = new ExtractIA_API_Client();
        $result = $client->process_multipage( 'tpl-1', [ 'b64-1', 'b64-2', 'b64-3' ] );
        $this->assertIsArray( $result );
        $this->assertEquals( 3, $result['pages'] );
    }

    public function test_get_document_summary_returns_string(): void {
        $this->set_response( 200, [ 'summary' => 'Invoice for office supplies totalling $99.90.' ] );
        $client = new ExtractIA_API_Client();
        $result = $client->get_document_summary( 'doc-abc' );
        $this->assertIsArray( $result );
        $this->assertStringContainsString( '99.90', $result['summary'] );
    }

    public function test_run_ocr_tool_returns_answer(): void {
        $this->set_response( 200, [ 'answer' => 'Yes', 'explanation' => 'Signature found at bottom.' ] );
        $client = new ExtractIA_API_Client();
        $result = $client->run_ocr_tool( 'tool-sig', 'data:image/jpeg;base64,abc', [] );
        $this->assertIsArray( $result );
        $this->assertEquals( 'Yes', $result['answer'] );
    }

    // ── Transient caching ─────────────────────────────────────────────────────

    public function test_templates_are_cached_after_first_call(): void {
        $templates = [ ['id' => 'tpl-1', 'name' => 'Invoice'] ];
        $this->set_response( 200, $templates );
        $client = new ExtractIA_API_Client();

        $client->get_templates();  // populates cache
        $GLOBALS['_wp_remote_response'] = $this->mock_response( 500, [] ); // would fail if called

        $result = $client->get_templates();  // should read from cache
        $this->assertIsArray( $result );
        $this->assertEquals( 'Invoice', $result[0]['name'] );
    }

    public function test_get_ocr_tools_cached(): void {
        $tools = [ ['id' => 'tool-1', 'name' => 'Signature Check', 'outputType' => 'YES_NO'] ];
        $this->set_response( 200, $tools );
        $client = new ExtractIA_API_Client();

        $client->get_ocr_tools();
        $GLOBALS['_wp_remote_response'] = $this->mock_response( 500, [] );

        $result = $client->get_ocr_tools();
        $this->assertIsArray( $result );
        $this->assertEquals( 'Signature Check', $result[0]['name'] );
    }

    // ── i18n key mapping ──────────────────────────────────────────────────────

    public function test_i18n_key_401(): void {
        $this->assertEquals( 'authError', ExtractIA_API_Client::i18n_key_for_status( 401 ) );
    }

    public function test_i18n_key_402(): void {
        $this->assertEquals( 'quotaExceeded', ExtractIA_API_Client::i18n_key_for_status( 402 ) );
    }

    public function test_i18n_key_403(): void {
        $this->assertEquals( 'tierError', ExtractIA_API_Client::i18n_key_for_status( 403 ) );
    }

    public function test_i18n_key_429(): void {
        $this->assertEquals( 'rateLimited', ExtractIA_API_Client::i18n_key_for_status( 429 ) );
    }

    public function test_i18n_key_500(): void {
        $this->assertEquals( 'genericError', ExtractIA_API_Client::i18n_key_for_status( 500 ) );
    }

    // ── Subuser management ────────────────────────────────────────────────────

    public function test_get_subusers_returns_array(): void {
        $subusers = [ [ 'id' => 'su-1', 'username' => 'alice', 'suspended' => false ] ];
        $this->set_response( 200, $subusers );
        $client = new ExtractIA_API_Client();
        $result = $client->get_subusers();
        $this->assertIsArray( $result );
        $this->assertEquals( 'alice', $result[0]['username'] );
    }

    public function test_toggle_suspend_returns_success(): void {
        $this->set_response( 200, [ 'suspended' => true ] );
        $client = new ExtractIA_API_Client();
        $result = $client->toggle_suspend_subuser( 'su-1' );
        $this->assertIsArray( $result );
        $this->assertTrue( $result['suspended'] );
    }
}
