<?php
/**
 * ExtractIA — Documented Hooks & Filters
 *
 * This file documents all WordPress actions and filters provided by the plugin.
 * Include it as a reference; the actual hooks are called throughout the codebase.
 *
 * The class registers a few convenience filters internally and provides static
 * helper methods so third-party code can interact with the plugin via PHP.
 *
 * ── ACTIONS ────────────────────────────────────────────────────────────────────
 *
 * extractia_before_process        ($template_id, $image_b64, $request)
 *   Fired before an image is sent to the API for extraction.
 *
 * extractia_after_process         ($document, $template_id, $request)
 *   Fired after a successful extraction. $document is the API result array.
 *
 * extractia_process_error         ($wp_error, $template_id, $request)
 *   Fired when process_image returns a WP_Error.
 *
 * extractia_agent_step_before     ($step_index, $step_config, $context)
 *   Fired before each step in an agent pipeline.
 *
 * extractia_agent_step_after      ($step_index, $step_config, $step_result, $context)
 *   Fired after each step completes (including errors).
 *
 * extractia_agent_complete        ($result, $agent_config)
 *   Fired when an agent pipeline finishes (any finalStatus).
 *
 * ── FILTERS ────────────────────────────────────────────────────────────────────
 *
 * extractia_upload_allowed        ($allowed, $user_id, $template_id)
 *   Control whether the current user can submit an upload.
 *   Return false to block with an authError response.
 *
 * extractia_template_list         ($templates, $user_id)
 *   Filter the list of templates shown in the selector.
 *
 * extractia_result_fields         ($fields, $template_id, $document)
 *   Filter which fields are rendered in the results table.
 *   $fields is an associative array ['key' => 'value'].
 *
 * extractia_max_file_mb           ($mb, $template_id)
 *   Override the maximum file size per-template or per-user.
 *
 * extractia_after_result_html     ($html, $document, $atts)
 *   Filter the results HTML block after it is generated.
 *
 * extractia_agent_steps           ($steps, $agent_config)
 *   Modify the steps array before an agent runs.
 *
 * extractia_agent_image           ($image_b64, $agent_config)
 *   Pre-process the image before it enters the agent pipeline.
 *
 * extractia_agent_result          ($result, $agent_config)
 *   Filter the final agent result before it is returned to the client.
 *
 * extractia_webhook_payload       ($payload, $document, $event)
 *   Filter the JSON payload sent to the configured webhook URL.
 *
 * extractia_rest_permission       ($allowed, $endpoint, $request)
 *   Control REST endpoint access. Return false to send a 403.
 *
 * extractia_shortcode_atts        ($atts, $shortcode_tag)
 *   Filter shortcode attributes before rendering.
 *
 * ── EXAMPLES ───────────────────────────────────────────────────────────────────
 *
 * // 1. Log every successful extraction to a custom table
 * add_action( 'extractia_after_process', function( $doc, $template_id ) {
 *     global $wpdb;
 *     $wpdb->insert( $wpdb->prefix . 'doc_log', [
 *         'doc_id'      => $doc['id'],
 *         'template'    => $template_id,
 *         'created_at'  => current_time('mysql'),
 *     ] );
 * }, 10, 2 );
 *
 * // 2. Block uploads for subscribers
 * add_filter( 'extractia_upload_allowed', function( $allowed, $user_id ) {
 *     if ( user_can( $user_id, 'subscriber' ) && ! user_can( $user_id, 'editor' ) ) {
 *         return false;
 *     }
 *     return $allowed;
 * }, 10, 2 );
 *
 * // 3. Always hide the 'internal_notes' field from front-end results
 * add_filter( 'extractia_result_fields', function( $fields ) {
 *     unset( $fields['internal_notes'] );
 *     return $fields;
 * } );
 *
 * // 4. Override max file size for a specific template
 * add_filter( 'extractia_max_file_mb', function( $mb, $template_id ) {
 *     return $template_id === 'tpl-scans' ? 15 : $mb;
 * }, 10, 2 );
 *
 * // 5. Enrich webhook payload with the WP post ID
 * add_filter( 'extractia_webhook_payload', function( $payload ) {
 *     $payload['postId'] = get_the_ID();
 *     return $payload;
 * } );
 *
 * // 6. Only show templates whose ID starts with 'pub-' for logged-out users
 * add_filter( 'extractia_template_list', function( $templates, $user_id ) {
 *     if ( ! $user_id ) {
 *         return array_filter( $templates, fn($t) => str_starts_with( $t['id'], 'pub-' ) );
 *     }
 *     return $templates;
 * }, 10, 2 );
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ExtractIA_Hooks {

    public function __construct() {
        // Apply core default filters that the plugin exposes
        add_filter( 'extractia_max_file_mb',   [ $this, 'default_max_file_mb'   ], 1, 2 );
        add_filter( 'extractia_template_list', [ $this, 'default_template_list' ], 1, 2 );
        add_filter( 'extractia_result_fields', [ $this, 'default_result_fields' ], 1, 3 );
        add_filter( 'extractia_upload_allowed',[ $this, 'default_upload_allowed'], 1, 3 );
        add_filter( 'extractia_webhook_payload',[ $this,'default_webhook_payload'], 1, 3 );
        add_filter( 'extractia_rest_permission',[ $this,'default_rest_permission'], 1, 3 );
        add_filter( 'extractia_shortcode_atts', [ $this, 'default_shortcode_atts' ], 1, 2 );
    }

    // ── Default filter implementations ────────────────────────────────────────

    /**
     * Read max_file_mb from WP option (can be overridden by theme/plugin).
     */
    public function default_max_file_mb( int $mb, string $template_id ): int {
        return (int) get_option( 'extractia_max_file_size_mb', $mb );
    }

    /**
     * Return templates as-is unless a later hook modifies them.
     */
    public function default_template_list( array $templates, int $user_id ): array {
        return $templates;
    }

    /**
     * Apply admin-configured result field whitelist.
     */
    public function default_result_fields( array $fields, string $template_id, array $document ): array {
        $whitelist = get_option( 'extractia_result_fields', '' );
        if ( empty( $whitelist ) ) {
            return $fields;
        }
        $keys = array_map( 'trim', explode( ',', $whitelist ) );
        $keys = array_filter( $keys );
        if ( empty( $keys ) ) {
            return $fields;
        }
        return array_intersect_key( $fields, array_flip( $keys ) );
    }

    /**
     * By default everyone who can reach the REST endpoint is allowed to upload.
     * Use extractia_upload_allowed filter to add restrictions.
     */
    public function default_upload_allowed( bool $allowed, int $user_id, string $template_id ): bool {
        return $allowed;
    }

    /**
     * Build the base webhook payload (can be extended via filter by third parties).
     */
    public function default_webhook_payload( array $payload, array $document, string $event ): array {
        return array_merge( [
            'event'     => $event,
            'source'    => 'extractia-wp',
            'siteUrl'   => get_rest_url( null, '/' ),
            'timestamp' => date( 'c' ),
        ], $payload );
    }

    /**
     * Default REST permission — always allowed (nonce verified by WP core).
     * Use extractia_rest_permission to restrict specific endpoints.
     */
    public function default_rest_permission( bool $allowed, string $endpoint, $request ): bool {
        return $allowed;
    }

    /**
     * Allow shortcode attributes to pass through unchanged by default.
     */
    public function default_shortcode_atts( array $atts, string $tag ): array {
        return $atts;
    }

    // ── Static convenience helpers ─────────────────────────────────────────────

    /**
     * Fire extractia_before_process and return (possibly modified) args.
     * Returns ['template_id' => ..., 'image' => ...].
     */
    public static function fire_before_process( string $template_id, string $image, $request = null ): array {
        do_action( 'extractia_before_process', $template_id, $image, $request );
        return [ 'template_id' => $template_id, 'image' => $image ];
    }

    /**
     * Fire extractia_after_process.
     */
    public static function fire_after_process( array $document, string $template_id, $request = null ): void {
        do_action( 'extractia_after_process', $document, $template_id, $request );
    }

    /**
     * Fire extractia_process_error.
     */
    public static function fire_process_error( WP_Error $error, string $template_id, $request = null ): void {
        do_action( 'extractia_process_error', $error, $template_id, $request );
    }

    /**
     * Filter result fields array.
     */
    public static function filter_result_fields( array $fields, string $template_id = '', array $document = [] ): array {
        return apply_filters( 'extractia_result_fields', $fields, $template_id, $document );
    }

    /**
     * Check upload permission.
     */
    public static function check_upload_allowed( int $user_id = 0, string $template_id = '' ): bool {
        return (bool) apply_filters( 'extractia_upload_allowed', true, $user_id, $template_id );
    }

    /**
     * Build webhook payload with filter applied.
     */
    public static function build_webhook_payload( array $data, string $event = 'document.processed' ): array {
        return apply_filters( 'extractia_webhook_payload', $data, $data, $event );
    }

    /**
     * Fire agent_complete action.
     */
    public static function fire_agent_complete( array $result, array $agent_config ): void {
        do_action( 'extractia_agent_complete', $result, $agent_config );
    }
}
