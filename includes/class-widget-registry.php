<?php
/**
 * ExtractIA — Widget Registry
 *
 * Stores named widget configurations so admins can define presets in the
 * WordPress dashboard and reference them from shortcodes and blocks via a
 * slug instead of repeating all options.
 *
 * Example: [extractia_upload config="invoice-scanner"]
 *
 * Each config supports all upload-widget options plus role gating,
 * per-config webhook URLs, and workflow mode override.
 *
 * Config keys (all optional, merged with defaults on ::get()):
 *
 *   name          string   Display name (admin only)
 *   templateId    string   Pre-selected template ID
 *   hideSelector  bool     Hide template dropdown
 *   multipage     bool     Allow multi-page documents
 *   showSummary   bool     Show AI summary button
 *   buttonText    string   Custom process button label
 *   title         string   Widget heading
 *   workflowMode  string   inline | redirect | webhook
 *   redirectUrl   string   Used when workflowMode = redirect
 *   webhookUrl    string   Per-config webhook URL (overrides global)
 *   maxFileMb     int      File-size cap
 *   resultFields  string   Comma-separated field keys to show
 *   cssClass      string   Extra CSS class on wrapper
 *   allowedRoles  array    WP role slugs; empty = allow all
 *   agentId       string   If set, renders as [extractia_agent] instead
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ExtractIA_Widget_Registry {

    private const OPTION_KEY = 'extractia_widget_configs';

    /** Default values applied when a stored key is absent */
    private const DEFAULTS = [
        'name'         => '',
        'templateId'   => '',
        'hideSelector' => false,
        'multipage'    => true,
        'showSummary'  => true,
        'buttonText'   => '',
        'title'        => '',
        'workflowMode' => 'inline',
        'redirectUrl'  => '',
        'webhookUrl'   => '',
        'maxFileMb'    => 5,
        'resultFields' => '',
        'cssClass'     => '',
        'allowedRoles' => [],
        'agentId'      => '',
    ];

    /** Valid workflow modes */
    private const VALID_MODES = [ 'inline', 'redirect', 'webhook' ];

    // ── CRUD ─────────────────────────────────────────────────────────────────

    /**
     * Save (create or update) a named config to WP options.
     */
    public static function save( string $slug, array $config ): bool {
        $all          = self::load_all();
        $all[ $slug ] = $config;
        return update_option( self::OPTION_KEY, $all );
    }

    /**
     * Get a single config merged with defaults. Returns defaults if slug not found.
     */
    public static function get( string $slug ): array {
        $all    = self::load_all();
        $stored = $all[ $slug ] ?? [];
        return array_merge( self::DEFAULTS, $stored );
    }

    /**
     * Return all stored configs (raw, no defaults applied).
     */
    public static function get_all(): array {
        return self::load_all();
    }

    /**
     * Delete a stored config.
     */
    public static function delete( string $slug ): bool {
        $all = self::load_all();
        if ( ! isset( $all[ $slug ] ) ) {
            return false;
        }
        unset( $all[ $slug ] );
        return update_option( self::OPTION_KEY, $all );
    }

    /**
     * Clear the in-memory cache and reload from DB on next access.
     * Called by tests to reset state between tests.
     */
    public static function reset(): void {
        // Clear cache
        $GLOBALS['_extractia_registry_cache'] = null;
    }

    // ── Validation ────────────────────────────────────────────────────────────

    /**
     * Validate a config array. Returns array of error code strings.
     */
    public static function validate( array $config ): array {
        $errors = [];

        if ( isset( $config['name'] ) && trim( $config['name'] ) === '' ) {
            $errors[] = 'name_required';
        }

        if ( isset( $config['workflowMode'] ) &&
             ! in_array( $config['workflowMode'], self::VALID_MODES, true ) ) {
            $errors[] = 'invalid_workflow_mode';
        }

        if ( isset( $config['maxFileMb'] ) ) {
            $mb = (int) $config['maxFileMb'];
            if ( $mb < 1 || $mb > 50 ) {
                $errors[] = 'max_file_mb_out_of_range';
            }
        }

        if ( isset( $config['workflowMode'] ) && $config['workflowMode'] === 'redirect' ) {
            if ( empty( $config['redirectUrl'] ) ) {
                $errors[] = 'redirect_url_required';
            }
        }

        return $errors;
    }

    // ── Role gating ───────────────────────────────────────────────────────────

    /**
     * Check whether the given user roles are allowed to use a config.
     *
     * @param string $slug      Config key.
     * @param array  $user_roles Current user's roles.
     * @return bool  True if allowed.
     */
    public static function is_allowed( string $slug, array $user_roles ): bool {
        $config       = self::get( $slug );
        $allowed_roles = (array) ( $config['allowedRoles'] ?? [] );

        if ( empty( $allowed_roles ) ) {
            return true;  // No restrictions
        }

        return (bool) array_intersect( $allowed_roles, $user_roles );
    }

    // ── Shortcode-friendly attribute builder ──────────────────────────────────

    /**
     * Convert a stored config to the shortcode attribute array expected by
     * ExtractIA_Shortcodes::upload_widget().
     */
    public static function to_shortcode_atts( string $slug ): array {
        $cfg = self::get( $slug );
        return [
            'template'      => $cfg['templateId'],
            'hide_selector' => $cfg['hideSelector'] ? 'true' : 'false',
            'multipage'     => $cfg['multipage'] ? 'true' : 'false',
            'show_summary'  => $cfg['showSummary'] ? 'true' : 'false',
            'button_text'   => $cfg['buttonText'],
            'title'         => $cfg['title'],
            'class'         => $cfg['cssClass'],
        ];
    }

    // ── REST-friendly representation ──────────────────────────────────────────

    /**
     * Get all configs as a flat array suitable for the /widget-configs REST endpoint.
     */
    public static function to_rest_list(): array {
        $out = [];
        foreach ( self::load_all() as $slug => $cfg ) {
            $cfg = array_merge( self::DEFAULTS, $cfg );
            $out[] = array_merge( $cfg, [ 'slug' => $slug ] );
        }
        return $out;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function load_all(): array {
        if ( isset( $GLOBALS['_extractia_registry_cache'] ) &&
             is_array( $GLOBALS['_extractia_registry_cache'] ) ) {
            return $GLOBALS['_extractia_registry_cache'];
        }
        $stored = get_option( self::OPTION_KEY, [] );
        $all    = is_array( $stored ) ? $stored : [];
        $GLOBALS['_extractia_registry_cache'] = $all;
        return $all;
    }
}
