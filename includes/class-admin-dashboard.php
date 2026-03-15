<?php
/**
 * ExtractIA — Admin Dashboard
 *
 * Renders the main admin dashboard, OCR Tools panel, and Sub-Users panel.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ExtractIA_Admin_Dashboard {

    public function __construct() {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'wp_ajax_extractia_admin_data',       [ $this, 'ajax_dashboard_data' ] );
        add_action( 'wp_ajax_extractia_suspend_subuser',  [ $this, 'ajax_suspend_subuser' ] );
        add_action( 'wp_ajax_extractia_delete_subuser',   [ $this, 'ajax_delete_subuser' ] );
        add_action( 'wp_ajax_extractia_run_ocr_admin',    [ $this, 'ajax_run_ocr_admin' ] );
    }

    public function enqueue_admin_assets( $hook ) {
        if ( strpos( $hook, 'extractia' ) === false ) {
            return;
        }
        wp_enqueue_style(
            'extractia-admin',
            EXTRACTIA_PLUGIN_URL . 'admin/css/admin.css',
            [],
            EXTRACTIA_VERSION
        );
        wp_enqueue_script(
            'extractia-admin',
            EXTRACTIA_PLUGIN_URL . 'admin/js/admin.js',
            [ 'wp-element' ],
            EXTRACTIA_VERSION,
            true
        );
        wp_localize_script( 'extractia-admin', 'ExtractIAAdmin', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'extractia_admin' ),
        ] );
    }

    // ── Dashboard page ─────────────────────────────────────────────────────────

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $api     = new ExtractIA_API_Client();
        $profile = $api->get_profile();
        $credits = $api->get_credits();
        $recent  = $api->get_recent_documents( 10 );

        $has_key = get_option( 'extractia_api_key', '' ) !== '';

        include EXTRACTIA_PLUGIN_DIR . 'admin/views/dashboard.php';
    }

    // ── OCR Tools panel ───────────────────────────────────────────────────────

    public static function render_ocr_tools() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $api   = new ExtractIA_API_Client();
        $tools = $api->get_ocr_tools();

        include EXTRACTIA_PLUGIN_DIR . 'admin/views/ocr-tools.php';
    }

    // ── Subusers panel ────────────────────────────────────────────────────────

    public static function render_subusers() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $api      = new ExtractIA_API_Client();
        $subusers = $api->get_subusers();

        include EXTRACTIA_PLUGIN_DIR . 'admin/views/subusers.php';
    }

    // ── AJAX: fetch dashboard data ────────────────────────────────────────────

    public function ajax_dashboard_data() {
        check_ajax_referer( 'extractia_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions', 403 );
        }

        $api     = new ExtractIA_API_Client();
        $profile = $api->get_profile();
        $credits = $api->get_credits();

        if ( is_wp_error( $profile ) ) {
            wp_send_json_error( ExtractIA_API_Client::format_error( $profile ) );
        }

        wp_send_json_success( [
            'profile' => $profile,
            'credits' => is_wp_error( $credits ) ? null : $credits,
        ] );
    }

    // ── AJAX: suspend/unsuspend subuser ───────────────────────────────────────

    public function ajax_suspend_subuser() {
        check_ajax_referer( 'extractia_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions', 403 );
        }

        $username = sanitize_text_field( wp_unslash( $_POST['username'] ?? '' ) );

        if ( empty( $username ) ) {
            wp_send_json_error( 'Missing username' );
        }

        $api    = new ExtractIA_API_Client();
        $result = $api->toggle_suspend_subuser( $username );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( ExtractIA_API_Client::format_error( $result ) );
        }

        wp_send_json_success( $result );
    }

    // ── AJAX: delete subuser ──────────────────────────────────────────────────

    public function ajax_delete_subuser() {
        check_ajax_referer( 'extractia_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions', 403 );
        }

        $username = sanitize_text_field( wp_unslash( $_POST['username'] ?? '' ) );

        if ( empty( $username ) ) {
            wp_send_json_error( 'Missing username' );
        }

        $api    = new ExtractIA_API_Client();
        $result = $api->delete_subuser( $username );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( ExtractIA_API_Client::format_error( $result ) );
        }

        wp_send_json_success( $result );
    }

    // ── AJAX: run OCR tool from admin ─────────────────────────────────────────

    public function ajax_run_ocr_admin() {
        check_ajax_referer( 'extractia_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions', 403 );
        }

        $tool_id = sanitize_text_field( wp_unslash( $_POST['tool_id'] ?? '' ) );
        $image   = wp_unslash( $_POST['image']   ?? '' );
        $params  = isset( $_POST['params'] ) ? (array) $_POST['params'] : [];

        if ( empty( $tool_id ) || empty( $image ) ) {
            wp_send_json_error( 'Missing tool_id or image' );
        }

        // Sanitize params values
        $clean_params = array_map( 'sanitize_text_field', $params );

        $api    = new ExtractIA_API_Client();
        $result = $api->run_ocr_tool( $tool_id, $image, $clean_params );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( ExtractIA_API_Client::format_error( $result ) );
        }

        wp_send_json_success( $result );
    }
}
