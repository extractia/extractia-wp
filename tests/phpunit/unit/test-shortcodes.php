<?php
/**
 * Unit tests — ExtractIA_Shortcodes
 *
 * Tests HTML output and shortcode attribute handling.
 * Uses output buffer to capture rendered HTML without a browser.
 */

use PHPUnit\Framework\TestCase;

class Test_Shortcodes extends TestCase {

    private function mock_response( int $code, array $body ): void {
        $GLOBALS['_wp_remote_response'] = [
            'response' => [ 'code' => $code, 'message' => 'OK' ],
            'body'     => json_encode( $body ),
        ];
    }

    protected function setUp(): void {
        $GLOBALS['_extractia_options']   = [
            'extractia_api_key'          => 'test-key',
            'extractia_default_template' => '',
            'extractia_allow_multipage'  => '1',
            'extractia_show_summary'     => '1',
            'extractia_custom_css_class' => '',
            'extractia_show_usage_bar'   => '0',
        ];
        $GLOBALS['_extractia_transients'] = [];
        $GLOBALS['_wp_remote_response']  = null;
    }

    // ── [extractia_upload] ─────────────────────────────────────────────────

    public function test_upload_widget_renders_dropzone(): void {
        $this->mock_response( 200, [
            ['id' => 'tpl-1', 'name' => 'Invoice'],
            ['id' => 'tpl-2', 'name' => 'Receipt'],
        ] );
        $sc  = new ExtractIA_Shortcodes();
        $out = $sc->upload_widget( [] );
        $this->assertStringContainsString( 'extractia-widget', $out );
        $this->assertStringContainsString( 'extractia-dropzone', $out );
    }

    public function test_upload_widget_renders_template_selector(): void {
        $this->mock_response( 200, [
            ['id' => 'tpl-1', 'name' => 'Invoice'],
        ] );
        $sc  = new ExtractIA_Shortcodes();
        $out = $sc->upload_widget( [ 'hide_selector' => 'false' ] );
        $this->assertStringContainsString( 'extractia-template-select', $out );
        $this->assertStringContainsString( 'Invoice', $out );
    }

    public function test_upload_widget_hides_selector_when_requested(): void {
        $this->mock_response( 200, [ ['id' => 'tpl-1', 'name' => 'Invoice'] ] );
        $sc  = new ExtractIA_Shortcodes();
        $out = $sc->upload_widget( [ 'hide_selector' => 'true', 'template' => 'tpl-1' ] );
        $this->assertStringContainsString( 'data-template="tpl-1"', $out );
        // The template step div should be hidden when hide_selector = true
        $this->assertStringContainsString( 'data-hide-selector="true"', $out );
    }

    public function test_upload_widget_uses_custom_title(): void {
        $this->mock_response( 200, [] );
        $sc  = new ExtractIA_Shortcodes();
        $out = $sc->upload_widget( [ 'title' => 'My Custom Title' ] );
        $this->assertStringContainsString( 'My Custom Title', $out );
    }

    public function test_upload_widget_uses_custom_button_text(): void {
        $this->mock_response( 200, [] );
        $sc  = new ExtractIA_Shortcodes();
        $out = $sc->upload_widget( [ 'button_text' => 'Extract Now' ] );
        $this->assertStringContainsString( 'data-button-text="Extract Now"', $out );
    }

    public function test_upload_widget_shows_error_on_api_failure(): void {
        $GLOBALS['_extractia_options']['extractia_api_key'] = '';   // no key
        $sc  = new ExtractIA_Shortcodes();
        $out = $sc->upload_widget( [] );
        $this->assertStringContainsString( 'extractia-error', $out );
    }

    public function test_upload_widget_applies_extra_css_class(): void {
        $this->mock_response( 200, [] );
        $sc  = new ExtractIA_Shortcodes();
        $out = $sc->upload_widget( [ 'class' => 'my-custom-class' ] );
        $this->assertStringContainsString( 'my-custom-class', $out );
    }

    public function test_upload_widget_escapes_xss_in_title(): void {
        $this->mock_response( 200, [] );
        $sc  = new ExtractIA_Shortcodes();
        $out = $sc->upload_widget( [ 'title' => '<script>alert(1)</script>' ] );
        $this->assertStringNotContainsString( '<script>', $out );
    }

    // ── [extractia_usage] ─────────────────────────────────────────────────

    public function test_usage_meter_renders_container(): void {
        $sc  = new ExtractIA_Shortcodes();
        $out = $sc->usage_meter( [] );
        $this->assertStringContainsString( 'extractia-usage', $out );
    }

    // ── [extractia_tool] ─────────────────────────────────────────────────

    public function test_ocr_tool_renders_dropzone(): void {
        $this->mock_response( 200, [
            'id' => 'tool-sig', 'name' => 'Signature Check',
            'outputType' => 'YES_NO', 'params' => [],
        ] );
        $sc  = new ExtractIA_Shortcodes();
        $out = $sc->ocr_tool( [ 'id' => 'tool-sig' ] );
        $this->assertStringContainsString( 'extractia-ocr-widget', $out );
        $this->assertStringContainsString( 'extractia-ocr-dropzone', $out );
    }

    public function test_ocr_tool_shows_error_without_id(): void {
        $sc  = new ExtractIA_Shortcodes();
        $out = $sc->ocr_tool( [] );
        $this->assertStringContainsString( 'extractia-error', $out );
    }

    // ── [extractia_agent] ─────────────────────────────────────────────────

    public function test_agent_shortcode_renders_when_registered(): void {
        ExtractIA_Agent::register( 'test-agent', [
            'name'  => 'My Agent',
            'steps' => [ [ 'type' => 'extract', 'templateId' => 'tpl-1' ] ],
        ] );
        $this->mock_response( 200, [ ['id' => 'tpl-1', 'name' => 'Invoice'] ] );
        $sc  = new ExtractIA_Shortcodes();
        $out = $sc->agent_widget( [ 'id' => 'test-agent' ] );
        $this->assertStringContainsString( 'extractia-agent', $out );
        $this->assertStringContainsString( 'My Agent', $out );
    }

    public function test_agent_shortcode_error_when_agent_not_registered(): void {
        $sc  = new ExtractIA_Shortcodes();
        $out = $sc->agent_widget( [ 'id' => 'ghost-agent' ] );
        $this->assertStringContainsString( 'extractia-error', $out );
    }

    // ── Widget config shortcode integration ───────────────────────────────

    public function test_upload_widget_loads_named_config(): void {
        ExtractIA_Widget_Registry::save( 'fast-scan', [
            'name'       => 'Fast Scan',
            'templateId' => 'tpl-receipt',
            'buttonText' => 'Quick Scan',
        ] );
        $this->mock_response( 200, [ ['id' => 'tpl-receipt', 'name' => 'Receipt'] ] );
        $sc  = new ExtractIA_Shortcodes();
        $out = $sc->upload_widget( [ 'config' => 'fast-scan' ] );
        $this->assertStringContainsString( 'data-template="tpl-receipt"', $out );
        $this->assertStringContainsString( 'Quick Scan', $out );
    }
}
