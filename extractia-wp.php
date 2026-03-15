<?php
/**
 * Plugin Name:  ExtractIA — Document Extraction & OCR
 * Plugin URI:   https://extractia.info
 * Description:  Integrate ExtractIA AI-powered document extraction into WordPress.
 *               Drag & drop documents, run OCR tools, manage templates and sub-users — all from your WP site.
 * Version:      1.0.0
 * Author:       ExtractIA Team
 * Author URI:   https://extractia.info
 * License:      GPL-2.0+
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  extractia-wp
 * Domain Path:  /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── Constants ─────────────────────────────────────────────────────────────────
define( 'EXTRACTIA_VERSION',    '1.0.0' );
define( 'EXTRACTIA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXTRACTIA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EXTRACTIA_API_BASE',   'https://api.extractia.info/api/public' );
define( 'EXTRACTIA_OPTION_KEY', 'extractia_api_key' );

// ── Autoload includes ─────────────────────────────────────────────────────────
foreach ( [
    'class-api-client',
    'class-hooks',
    'class-agent',
    'class-widget-registry',
    'class-settings',
    'class-admin-dashboard',
    'class-shortcodes',
    'class-rest-proxy',
    'class-blocks',
] as $file ) {
    require_once EXTRACTIA_PLUGIN_DIR . "includes/{$file}.php";
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', 'extractia_init' );

function extractia_init() {
    load_plugin_textdomain( 'extractia-wp', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    new ExtractIA_Hooks();
    new ExtractIA_Settings();
    new ExtractIA_Admin_Dashboard();
    new ExtractIA_Shortcodes();
    new ExtractIA_REST_Proxy();
    new ExtractIA_Blocks();
}

// ── Public scripts & styles ───────────────────────────────────────────────────
add_action( 'wp_enqueue_scripts', 'extractia_public_scripts' );

function extractia_public_scripts() {
    wp_enqueue_style(
        'extractia',
        EXTRACTIA_PLUGIN_URL . 'public/css/extractia.css',
        [],
        EXTRACTIA_VERSION
    );

    wp_enqueue_script(
        'extractia-widget',
        EXTRACTIA_PLUGIN_URL . 'public/js/upload-widget.js',
        [],
        EXTRACTIA_VERSION,
        true
    );

    wp_enqueue_script(
        'extractia-ocr',
        EXTRACTIA_PLUGIN_URL . 'public/js/ocr-tool.js',
        [ 'extractia-widget' ],
        EXTRACTIA_VERSION,
        true
    );

    wp_enqueue_script(
        'extractia-agent',
        EXTRACTIA_PLUGIN_URL . 'public/js/agent-widget.js',
        [ 'extractia-widget' ],
        EXTRACTIA_VERSION,
        true
    );

    // Pass config & i18n strings to JS
    wp_localize_script( 'extractia-widget', 'ExtractIAConfig', [
        'restUrl'  => esc_url_raw( get_rest_url( null, 'extractia/v1' ) ),
        'nonce'    => wp_create_nonce( 'wp_rest' ),
        'version'  => EXTRACTIA_VERSION,
        'maxFileMb' => (int) get_option( 'extractia_max_file_size_mb', 5 ),
        'i18n'     => [
            'dropHere'        => __( 'Drop your document here or click to browse', 'extractia-wp' ),
            'orUseCamera'     => __( 'or use camera', 'extractia-wp' ),
            'selectTemplate'  => __( 'Select a form template', 'extractia-wp' ),
            'processing'      => __( 'Processing…', 'extractia-wp' ),
            'done'            => __( 'Extraction complete', 'extractia-wp' ),
            'addPage'         => __( '+ Add page', 'extractia-wp' ),
            'process'         => __( 'Process document', 'extractia-wp' ),
            'reset'           => __( 'Start over', 'extractia-wp' ),
            'aiSummary'       => __( 'Generate AI summary', 'extractia-wp' ),
            'copyJson'        => __( 'Copy JSON', 'extractia-wp' ),
            'downloadCsv'     => __( 'Download CSV', 'extractia-wp' ),
            'noTemplate'      => __( 'Please select a template first.', 'extractia-wp' ),
            'noImage'         => __( 'Please add at least one image.', 'extractia-wp' ),
            'fileTooLarge'    => __( 'File exceeds the maximum allowed size.', 'extractia-wp' ),
            'unsupportedType' => __( 'Unsupported file type. Use JPG, PNG, WEBP, or PDF.', 'extractia-wp' ),
            'quotaExceeded'   => __( 'Document quota reached. Upgrade your plan to continue.', 'extractia-wp' ),
            'tierError'       => __( 'Your current plan does not support this feature.', 'extractia-wp' ),
            'authError'       => __( 'API key error. Please contact the site administrator.', 'extractia-wp' ),
            'rateLimited'     => __( 'Too many requests. Please wait a moment and try again.', 'extractia-wp' ),
            'genericError'    => __( 'Something went wrong. Please try again.', 'extractia-wp' ),
            'runTool'         => __( 'Analyze', 'extractia-wp' ),
            'toolResult'      => __( 'Result', 'extractia-wp' ),
            'runAgent'        => __( 'Run Agent', 'extractia-wp' ),
            'runningAgent'    => __( 'Running agent…', 'extractia-wp' ),
            'agentStopped'    => __( 'Agent stopped by condition.', 'extractia-wp' ),
            'stepPending'     => __( 'Pending', 'extractia-wp' ),
            'stepRunning'     => __( 'Running', 'extractia-wp' ),
            'stepDone'        => __( 'Done', 'extractia-wp' ),
            'stepError'       => __( 'Error', 'extractia-wp' ),
            'stepStopped'     => __( 'Stopped', 'extractia-wp' ),
            'stepSkipped'     => __( 'Skipped', 'extractia-wp' ),
        ],
    ] );
}

// ── Activation / Deactivation ─────────────────────────────────────────────────
register_activation_hook( __FILE__, 'extractia_activate' );
register_deactivation_hook( __FILE__, 'extractia_deactivate' );

function extractia_activate() {
    $defaults = [
        'extractia_api_key'           => '',
        'extractia_default_template'  => '',
        'extractia_workflow_mode'     => 'inline',   // inline | redirect | webhook
        'extractia_redirect_url'      => '',
        'extractia_webhook_url'       => '',
        'extractia_show_summary'      => '1',
        'extractia_allow_multipage'   => '1',
        'extractia_max_file_size_mb'  => '5',
        'extractia_result_fields'     => '',          // comma-separated field labels to display
        'extractia_custom_css_class'  => '',
        'extractia_show_usage_bar'    => '1',
        'extractia_agents'            => [],
        'extractia_widget_configs'    => [],
    ];
    foreach ( $defaults as $key => $value ) {
        add_option( $key, $value );
    }
}

function extractia_deactivate() {
    delete_transient( 'extractia_templates_cache' );
    delete_transient( 'extractia_ocr_tools_cache' );
    delete_transient( 'extractia_profile_cache' );
}
