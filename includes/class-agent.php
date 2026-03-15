<?php
/**
 * ExtractIA — AI Agent Pipeline
 *
 * An "agent" is a named, multi-step processing pipeline stored as a WP option.
 * Each step is one of:
 *
 *   extract    — Run extraction on the provided image with a given templateId.
 *   ocr_tool   — Run an OCR tool on the image with optional params.
 *   condition  — Inspect an extracted field and branch (continue | stop | goto:<label>).
 *   webhook    — POST the accumulated context to an external URL.
 *   summary    — Fetch AI summary for the last extracted document.
 *
 * Usage from PHP:
 *   ExtractIA_Agent::register('invoice-flow', [ 'name' => '...', 'steps' => [...] ]);
 *   $agent = new ExtractIA_Agent( ExtractIA_Agent::get('invoice-flow') );
 *   $result = $agent->run('data:image/jpeg;base64,...');
 *
 * Usage from shortcode: [extractia_agent id="invoice-flow"]
 * Usage from REST:      POST /wp-json/extractia/v1/agent-run { agentId, image }
 *
 * The agent registry persists to the extractia_agents WP option.
 * Programmatic registrations via ::register() override stored ones in memory
 * but do not write to the DB (use ::save() for persistence).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ExtractIA_Agent {

    private const OPTION_KEY = 'extractia_agents';

    /** In-memory registry (overrides DB during a request) */
    private static array $registry = [];

    private array $config;

    /** Valid step types */
    private const VALID_STEP_TYPES = [ 'extract', 'ocr_tool', 'condition', 'webhook', 'summary' ];

    // ── Static registry ───────────────────────────────────────────────────────

    /**
     * Register an agent config in memory for this request.
     * Does NOT persist to DB. Use ::save() to persist.
     */
    public static function register( string $id, array $config ): void {
        self::$registry[ $id ] = $config;
    }

    /**
     * Save an agent config to WP options (persists across requests).
     */
    public static function save( string $id, array $config ): bool {
        $all          = self::load_all_from_db();
        $all[ $id ]   = $config;
        self::$registry[ $id ] = $config;
        return update_option( self::OPTION_KEY, $all );
    }

    /**
     * Delete a stored agent config.
     */
    public static function delete( string $id ): bool {
        unset( self::$registry[ $id ] );
        $all = self::load_all_from_db();
        unset( $all[ $id ] );
        return update_option( self::OPTION_KEY, $all );
    }

    /**
     * Get a single agent config, merging memory registry over DB.
     * Returns null if no config found.
     */
    public static function get( string $id ): ?array {
        if ( isset( self::$registry[ $id ] ) ) {
            return self::$registry[ $id ];
        }
        $all = self::load_all_from_db();
        return $all[ $id ] ?? null;
    }

    /**
     * Get all registered agents (memory + DB, memory takes precedence).
     */
    public static function get_all(): array {
        $db  = self::load_all_from_db();
        return array_merge( $db, self::$registry );
    }

    /**
     * Reset in-memory registry (used by tests).
     */
    public static function reset(): void {
        self::$registry = [];
    }

    // ── Config validation ─────────────────────────────────────────────────────

    /**
     * Validate a config array. Returns array of error code strings (empty = valid).
     */
    public static function validate_config( array $config ): array {
        $errors = [];

        if ( empty( $config['name'] ) ) {
            $errors[] = 'name_required';
        }

        if ( empty( $config['steps'] ) || ! is_array( $config['steps'] ) ) {
            $errors[] = 'steps_required';
            return $errors;
        }

        foreach ( $config['steps'] as $i => $step ) {
            $type = $step['type'] ?? '';
            if ( ! in_array( $type, self::VALID_STEP_TYPES, true ) ) {
                $errors[] = "step_{$i}_invalid_type";
                continue;
            }
            if ( $type === 'extract' && empty( $step['templateId'] ) ) {
                $errors[] = "step_{$i}_templateId_required";
            }
            if ( $type === 'ocr_tool' && empty( $step['toolId'] ) ) {
                $errors[] = "step_{$i}_toolId_required";
            }
            if ( $type === 'condition' ) {
                if ( empty( $step['field'] ) )    $errors[] = "step_{$i}_field_required";
                if ( empty( $step['operator'] ) ) $errors[] = "step_{$i}_operator_required";
                if ( ! isset( $step['value'] ) )  $errors[] = "step_{$i}_value_required";
            }
            if ( $type === 'webhook' && empty( $step['url'] ) ) {
                $errors[] = "step_{$i}_url_required";
            }
        }

        return $errors;
    }

    // ── Instance ──────────────────────────────────────────────────────────────

    public function __construct( array $config ) {
        $this->config = $config;
    }

    /**
     * Execute the agent pipeline.
     *
     * @param string $image   Base-64 image data URI.
     * @param array  $options Extra options: [ 'context' => [...] ]
     * @return array Result: {
     *   finalStatus: 'done'|'stopped'|'error',
     *   lastDoc:     ?array,
     *   steps:       array,
     * }
     */
    public function run( string $image, array $options = [] ): array {
        $api       = new ExtractIA_API_Client();
        $steps     = $this->config['steps'] ?? [];
        $results   = [];
        $context   = $options['context'] ?? [];   // accumulated extracted data
        $last_doc  = null;
        $final_status = 'done';

        $steps = apply_filters( 'extractia_agent_steps', $steps, $this->config );
        $image = apply_filters( 'extractia_agent_image', $image, $this->config );

        foreach ( $steps as $i => $step ) {
            $type = $step['type'] ?? '';
            $label = $step['label'] ?? ucfirst( $type );
            $step_result = [ 'type' => $type, 'label' => $label, 'status' => 'running' ];

            do_action( 'extractia_agent_step_before', $i, $step, $context );

            switch ( $type ) {

                // ── extract ──────────────────────────────────────────────────
                case 'extract':
                    $template_id = $step['templateId'] ?? '';
                    $doc = $api->process_image( $template_id, $image );
                    if ( is_wp_error( $doc ) ) {
                        $status = (int) ( $doc->get_error_data()['status'] ?? 500 );
                        $step_result['status']  = 'error';
                        $step_result['error']   = $doc->get_error_message();
                        $step_result['i18nKey'] = ExtractIA_API_Client::i18n_key_for_status( $status );
                        $final_status = 'error';
                    } else {
                        $last_doc = $doc;
                        $context  = array_merge( $context, (array) ( $doc['data'] ?? [] ) );
                        $step_result['status'] = 'done';
                        $step_result['docId']  = $doc['id'] ?? null;
                    }
                    break;

                // ── ocr_tool ─────────────────────────────────────────────────
                case 'ocr_tool':
                    $tool_id = $step['toolId'] ?? '';
                    $params  = $step['params'] ?? [];
                    $res = $api->run_ocr_tool( $tool_id, $image, $params );
                    if ( is_wp_error( $res ) ) {
                        $status = (int) ( $res->get_error_data()['status'] ?? 500 );
                        $step_result['status']  = 'error';
                        $step_result['error']   = $res->get_error_message();
                        $step_result['i18nKey'] = ExtractIA_API_Client::i18n_key_for_status( $status );
                        $final_status = 'error';
                    } else {
                        $step_result['status']      = 'done';
                        $step_result['answer']      = $res['answer'] ?? '';
                        $step_result['explanation'] = $res['explanation'] ?? '';
                        $context[ '__tool_' . $tool_id ] = $res['answer'] ?? '';
                    }
                    break;

                // ── condition ────────────────────────────────────────────────
                case 'condition':
                    $field    = $step['field']    ?? '';
                    $operator = $step['operator'] ?? 'equals';
                    $value    = $step['value']    ?? '';
                    $on_true  = $step['onTrue']   ?? 'continue';
                    $on_false = $step['onFalse']  ?? 'continue';

                    $actual  = $context[ $field ] ?? null;
                    $matches = $this->evaluate_condition( $actual, $operator, $value );
                    $outcome = $matches ? $on_true : $on_false;

                    $step_result['status']  = $outcome === 'stop' ? 'stopped' : 'done';
                    $step_result['outcome'] = $outcome;
                    $step_result['field']   = $field;
                    $step_result['actual']  = $actual;

                    if ( $outcome === 'stop' ) {
                        $results[]    = $step_result;
                        $final_status = 'stopped';
                        break 2;   // exit foreach
                    }
                    break;

                // ── webhook ──────────────────────────────────────────────────
                case 'webhook':
                    $url = $step['url'] ?? '';
                    if ( ! empty( $url ) ) {
                        wp_remote_post( $url, [
                            'body'     => wp_json_encode( [
                                'event'    => 'agent.step.webhook',
                                'agentId'  => $this->config['id'] ?? '',
                                'context'  => $context,
                                'lastDoc'  => $last_doc,
                            ] ),
                            'headers'  => [ 'Content-Type' => 'application/json' ],
                            'timeout'  => 0.01,
                            'blocking' => false,
                        ] );
                    }
                    $step_result['status'] = 'done';
                    break;

                // ── summary ──────────────────────────────────────────────────
                case 'summary':
                    if ( ! empty( $last_doc['id'] ) ) {
                        $res = $api->get_document_summary( $last_doc['id'] );
                        if ( is_wp_error( $res ) ) {
                            $step_result['status'] = 'error';
                            $step_result['error']  = $res->get_error_message();
                        } else {
                            $step_result['status']  = 'done';
                            $step_result['summary'] = $res['summary'] ?? '';
                            $context['__summary']   = $step_result['summary'];
                        }
                    } else {
                        $step_result['status'] = 'skipped';
                        $step_result['reason'] = 'no_document';
                    }
                    break;
            }

            do_action( 'extractia_agent_step_after', $i, $step, $step_result, $context );
            $results[] = $step_result;

            if ( $final_status === 'error' ) {
                break;  // Stop on first error
            }
        }

        $output = [
            'finalStatus' => $final_status,
            'lastDoc'     => $last_doc,
            'context'     => $context,
            'steps'       => $results,
        ];

        return apply_filters( 'extractia_agent_result', $output, $this->config );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Evaluate a condition operator against an actual value.
     */
    private function evaluate_condition( mixed $actual, string $operator, mixed $expected ): bool {
        return match ( $operator ) {
            'equals'         => (string) $actual === (string) $expected,
            'not_equals'     => (string) $actual !== (string) $expected,
            'contains'       => str_contains( (string) $actual, (string) $expected ),
            'not_contains'   => ! str_contains( (string) $actual, (string) $expected ),
            'starts_with'    => str_starts_with( (string) $actual, (string) $expected ),
            'ends_with'      => str_ends_with( (string) $actual, (string) $expected ),
            'greater_than'   => (float) $actual > (float) $expected,
            'less_than'      => (float) $actual < (float) $expected,
            'is_empty'       => empty( $actual ),
            'is_not_empty'   => ! empty( $actual ),
            default          => false,
        };
    }

    /**
     * Load all agents from WP options DB.
     */
    private static function load_all_from_db(): array {
        $stored = get_option( self::OPTION_KEY, [] );
        return is_array( $stored ) ? $stored : [];
    }
}
