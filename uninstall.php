<?php
/**
 * ExtractIA WP — Uninstall
 *
 * Runs when the user clicks "Delete" from the Plugins screen.
 * Removes all plugin options and transients from the database.
 *
 * @package ExtractIA_WP
 */

// Safety check: this file must only be called by WordPress during uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// ── Plugin options ──────────────────────────────────────────────────────────

$options = [
	'extractia_api_key',
	'extractia_default_template',
	'extractia_workflow_mode',
	'extractia_redirect_url',
	'extractia_webhook_url',
	'extractia_show_summary',
	'extractia_allow_multipage',
	'extractia_max_file_size_mb',
	'extractia_result_fields',
	'extractia_custom_css_class',
	'extractia_show_usage_bar',
];

foreach ( $options as $option ) {
	delete_option( $option );
}

// ── API cache transients ────────────────────────────────────────────────────

delete_transient( 'extractia_templates_cache' );
delete_transient( 'extractia_ocr_tools_cache' );
delete_transient( 'extractia_profile_cache' );

// ── Multisite support ───────────────────────────────────────────────────────

if ( is_multisite() ) {
	$sites = get_sites( [ 'fields' => 'ids' ] );
	foreach ( $sites as $site_id ) {
		switch_to_blog( $site_id );
		foreach ( $options as $option ) {
			delete_option( $option );
		}
		delete_transient( 'extractia_templates_cache' );
		delete_transient( 'extractia_ocr_tools_cache' );
		delete_transient( 'extractia_profile_cache' );
		restore_current_blog();
	}
}
