<?php
/**
 * Unit tests — ExtractIA_Widget_Registry
 *
 * Tests named widget configs: CRUD in WP options,
 * merge with defaults, validation, and shortcode integration.
 */

use PHPUnit\Framework\TestCase;

class Test_Widget_Registry extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_extractia_options']   = [];
        $GLOBALS['_extractia_transients'] = [];
        $GLOBALS['_wp_remote_response']  = null;
        // Reset registry between tests
        ExtractIA_Widget_Registry::reset();
    }

    // ── CRUD ─────────────────────────────────────────────────────────────────

    public function test_save_and_get(): void {
        $config = [
            'name'          => 'Invoice Scanner',
            'templateId'    => 'tpl-invoice',
            'multipage'     => true,
            'showSummary'   => true,
            'buttonText'    => 'Scan Invoice',
            'allowedRoles'  => [],
            'maxFileMb'     => 8,
            'workflowMode'  => 'inline',
        ];
        ExtractIA_Widget_Registry::save( 'invoice-scanner', $config );
        $result = ExtractIA_Widget_Registry::get( 'invoice-scanner' );
        $this->assertEquals( 'Invoice Scanner', $result['name'] );
        $this->assertEquals( 'tpl-invoice', $result['templateId'] );
        $this->assertEquals( 'Scan Invoice', $result['buttonText'] );
    }

    public function test_get_unknown_config_returns_defaults(): void {
        $result = ExtractIA_Widget_Registry::get( 'does-not-exist' );
        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'templateId', $result );
        $this->assertEquals( '', $result['templateId'] );
    }

    public function test_get_all_returns_all_saved(): void {
        ExtractIA_Widget_Registry::save( 'cfg-a', [ 'name' => 'A' ] );
        ExtractIA_Widget_Registry::save( 'cfg-b', [ 'name' => 'B' ] );
        $all = ExtractIA_Widget_Registry::get_all();
        $this->assertArrayHasKey( 'cfg-a', $all );
        $this->assertArrayHasKey( 'cfg-b', $all );
    }

    public function test_delete_removes_config(): void {
        ExtractIA_Widget_Registry::save( 'to-delete', [ 'name' => 'Delete Me' ] );
        ExtractIA_Widget_Registry::delete( 'to-delete' );
        $result = ExtractIA_Widget_Registry::get( 'to-delete' );
        $this->assertEquals( '', $result['templateId'] ); // returns defaults
    }

    // ── Persistence ──────────────────────────────────────────────────────────

    public function test_saved_configs_persist_in_wp_options(): void {
        ExtractIA_Widget_Registry::save( 'persistent', [ 'name' => 'Persisted', 'templateId' => 'tpl-x' ] );
        // Simulate new request by re-creating registry instance
        ExtractIA_Widget_Registry::reset();
        $result = ExtractIA_Widget_Registry::get( 'persistent' );
        $this->assertEquals( 'Persisted', $result['name'] );
    }

    // ── Merge with defaults ──────────────────────────────────────────────────

    public function test_partial_config_merged_with_defaults(): void {
        ExtractIA_Widget_Registry::save( 'partial', [ 'name' => 'Partial', 'templateId' => 'tpl-1' ] );
        $cfg = ExtractIA_Widget_Registry::get( 'partial' );
        // All default keys must be present
        $this->assertArrayHasKey( 'multipage',    $cfg );
        $this->assertArrayHasKey( 'showSummary',  $cfg );
        $this->assertArrayHasKey( 'workflowMode', $cfg );
        $this->assertArrayHasKey( 'maxFileMb',    $cfg );
        $this->assertArrayHasKey( 'allowedRoles', $cfg );
    }

    // ── Validation ───────────────────────────────────────────────────────────

    public function test_validate_accepts_valid_config(): void {
        $errors = ExtractIA_Widget_Registry::validate( [
            'name'         => 'Valid',
            'templateId'   => 'tpl-1',
            'workflowMode' => 'inline',
            'maxFileMb'    => 5,
        ] );
        $this->assertEmpty( $errors );
    }

    public function test_validate_rejects_empty_name(): void {
        $errors = ExtractIA_Widget_Registry::validate( [ 'name' => '' ] );
        $this->assertContains( 'name_required', $errors );
    }

    public function test_validate_rejects_invalid_workflow_mode(): void {
        $errors = ExtractIA_Widget_Registry::validate( [
            'name'         => 'Bad Mode',
            'workflowMode' => 'fly',
        ] );
        $this->assertContains( 'invalid_workflow_mode', $errors );
    }

    public function test_validate_rejects_max_file_mb_above_50(): void {
        $errors = ExtractIA_Widget_Registry::validate( [
            'name'      => 'Big',
            'maxFileMb' => 100,
        ] );
        $this->assertContains( 'max_file_mb_out_of_range', $errors );
    }

    public function test_validate_rejects_redirect_mode_without_url(): void {
        $errors = ExtractIA_Widget_Registry::validate( [
            'name'         => 'Redirect',
            'workflowMode' => 'redirect',
            'redirectUrl'  => '',
        ] );
        $this->assertContains( 'redirect_url_required', $errors );
    }

    // ── Role gating ──────────────────────────────────────────────────────────

    public function test_is_allowed_when_no_role_restrictions(): void {
        ExtractIA_Widget_Registry::save( 'open', [ 'name' => 'Open', 'allowedRoles' => [] ] );
        $this->assertTrue( ExtractIA_Widget_Registry::is_allowed( 'open', [] ) );
    }

    public function test_is_allowed_when_user_has_required_role(): void {
        ExtractIA_Widget_Registry::save( 'staff', [
            'name'         => 'Staff Only',
            'allowedRoles' => [ 'editor', 'author' ],
        ] );
        $this->assertTrue( ExtractIA_Widget_Registry::is_allowed( 'staff', [ 'editor' ] ) );
    }

    public function test_is_not_allowed_when_user_lacks_role(): void {
        ExtractIA_Widget_Registry::save( 'admin-only', [
            'name'         => 'Admins',
            'allowedRoles' => [ 'administrator' ],
        ] );
        $this->assertFalse( ExtractIA_Widget_Registry::is_allowed( 'admin-only', [ 'subscriber' ] ) );
    }
}
