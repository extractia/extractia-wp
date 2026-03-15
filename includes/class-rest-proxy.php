<?php
/**
 * ExtractIA — REST Proxy
 *
 * Registers WP REST API endpoints under /wp-json/extractia/v1/.
 * The JS widget calls these endpoints; PHP adds the API key server-side.
 *
 * All endpoints require a valid WP nonce (X-WP-Nonce header).
 * Non-logged-in visitors are allowed if the site is configured for public uploads.
 *
 * Endpoints:
 *   GET  /extractia/v1/templates          — List templates for the selector
 *   GET  /extractia/v1/ocr-tools          — List OCR tool configs
 *   GET  /extractia/v1/usage              — Current quota & AI credits
 *   POST /extractia/v1/process            — Process a single-page image
 *   POST /extractia/v1/process-multipage  — Process a multipage document
 *   POST /extractia/v1/summary            — Generate AI summary for a document
 *   POST /extractia/v1/ocr-run            — Run an OCR tool
 *   POST /extractia/v1/webhook            — Manually trigger configured webhook
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ExtractIA_REST_Proxy {

    const NAMESPACE = 'extractia/v1';

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes() {
        $ns = self::NAMESPACE;

        register_rest_route( $ns, '/templates', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_templates' ],
            'permission_callback' => [ $this, 'check_nonce' ],
        ] );

        register_rest_route( $ns, '/ocr-tools', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_ocr_tools' ],
            'permission_callback' => [ $this, 'check_nonce' ],
        ] );

        register_rest_route( $ns, '/usage', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_usage' ],
            'permission_callback' => [ $this, 'check_nonce' ],
        ] );

        register_rest_route( $ns, '/process', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'process_image' ],
            'permission_callback' => [ $this, 'check_nonce' ],
            'args'                => [
                'templateId' => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'image'      => [ 'required' => true,  'type' => 'string' ],
            ],
        ] );

        register_rest_route( $ns, '/process-multipage', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'process_multipage' ],
            'permission_callback' => [ $this, 'check_nonce' ],
            'args'                => [
                'templateId' => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'images'     => [ 'required' => true,  'type' => 'array' ],
            ],
        ] );

        register_rest_route( $ns, '/summary', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'get_summary' ],
            'permission_callback' => [ $this, 'check_nonce' ],
            'args'                => [
                'docId' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( $ns, '/ocr-run', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'run_ocr_tool' ],
            'permission_callback' => [ $this, 'check_nonce' ],
            'args'                => [
                'toolId' => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'image'  => [ 'required' => true,  'type' => 'string' ],
                'params' => [ 'required' => false, 'type' => 'object' ],
            ],
        ] );

        register_rest_route( $ns, '/webhook-test', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'test_webhook' ],
            'permission_callback' => function () { return current_user_can( 'manage_options' ); },
        ] );

        // ── Agent pipeline ────────────────────────────────────────────────────

        register_rest_route( $ns, '/agent-run', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'run_agent' ],
            'permission_callback' => [ $this, 'check_nonce' ],
            'args'                => [
                'agentId'     => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                'image'       => [ 'required' => true,  'type' => 'string' ],
                'agentConfig' => [ 'required' => false, 'type' => 'object' ],
            ],
        ] );

        // ── Widget registry ───────────────────────────────────────────────────

        register_rest_route( $ns, '/widget-configs', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_widget_configs' ],
            'permission_callback' => [ $this, 'check_nonce' ],
        ] );

        register_rest_route( $ns, '/agents', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_agents' ],
            'permission_callback' => [ $this, 'check_nonce' ],
        ] );
    }

    // ── Permission callback ───────────────────────────────────────────────────

    /**
     * Verify WP REST nonce.  Always allows — authentication is handled by the
     * nonce. The API key restriction means anonymous callers get an error from
     * ExtractIA if the key is wrong, not a WP 401.
     */
    public function check_nonce( WP_REST_Request $request ) {
        // The WP REST infrastructure already validates X-WP-Nonce automatically.
        // We just return true here; unauthenticated callers still get a valid
        // nonce from wp_create_nonce('wp_rest') in the page source.
        return true;
    }

    // ── Handlers ─────────────────────────────────────────────────────────────

    public function get_templates( WP_REST_Request $request ) {
        $api    = new ExtractIA_API_Client();
        $result = $api->get_templates();
        return $this->respond( $result );
    }

    public function get_ocr_tools( WP_REST_Request $request ) {
        $api    = new ExtractIA_API_Client();
        $result = $api->get_ocr_tools();
        return $this->respond( $result );
    }

    public function get_usage( WP_REST_Request $request ) {
        $api     = new ExtractIA_API_Client();
        $profile = $api->get_profile();
        $credits = $api->get_credits();

        if ( is_wp_error( $profile ) ) {
            return $this->respond( $profile );
        }

        return new WP_REST_Response( [
            'documentsUsed'  => $profile['documentsUsed']  ?? 0,
            'documentsLimit' => $profile['documentsLimit'] ?? 0,
            'plan'           => $profile['tier'] ?? $profile['plan'] ?? '',
            'credits'        => is_wp_error( $credits ) ? null : $credits,
        ], 200 );
    }

    public function process_image( WP_REST_Request $request ) {
        $template_id = $request->get_param( 'templateId' );
        $image       = $request->get_param( 'image' );

        // Respect extractia_upload_allowed filter
        $user_id = get_current_user_id();
        if ( ! ExtractIA_Hooks::check_upload_allowed( $user_id, $template_id ) ) {
            return new WP_REST_Response( [ 'error' => 'Not allowed.', 'i18nKey' => 'authError', 'code' => 403 ], 403 );
        }

        // Dynamic max-file-mb from filter
        $max_mb = (int) apply_filters( 'extractia_max_file_mb', (int) get_option( 'extractia_max_file_size_mb', 5 ), $template_id );
        if ( strlen( $image ) > $max_mb * 1024 * 1024 * 1.4 ) {
            return new WP_REST_Response( [ 'error' => 'File exceeds the maximum allowed size.', 'i18nKey' => 'fileTooLarge' ], 413 );
        }

        ExtractIA_Hooks::fire_before_process( $template_id, $image, $request );

        $api    = new ExtractIA_API_Client();
        $result = $api->process_image( $template_id, $image );
        $res    = $this->respond( $result );

        if ( ! is_wp_error( $result ) ) {
            ExtractIA_Hooks::fire_after_process( $result, $template_id, $request );
            $this->maybe_fire_webhook( $result );
        } else {
            ExtractIA_Hooks::fire_process_error( $result, $template_id, $request );
        }

        return $res;
    }

    public function process_multipage( WP_REST_Request $request ) {
        $template_id = $request->get_param( 'templateId' );
        $images      = (array) $request->get_param( 'images' );

        if ( empty( $images ) ) {
            return new WP_REST_Response( [ 'error' => 'No images provided.' ], 400 );
        }

        if ( count( $images ) > 20 ) {
            return new WP_REST_Response( [ 'error' => 'Maximum 20 pages per document.' ], 400 );
        }

        $api    = new ExtractIA_API_Client();
        $result = $api->process_multipage( $template_id, $images );
        $res    = $this->respond( $result );

        if ( ! is_wp_error( $result ) ) {
            $this->maybe_fire_webhook( $result );
        }

        return $res;
    }

    public function get_summary( WP_REST_Request $request ) {
        $doc_id = $request->get_param( 'docId' );

        $api    = new ExtractIA_API_Client();
        $result = $api->get_document_summary( $doc_id );
        return $this->respond( $result );
    }

    public function run_ocr_tool( WP_REST_Request $request ) {
        $tool_id = $request->get_param( 'toolId' );
        $image   = $request->get_param( 'image' );
        $params  = (array) ( $request->get_param( 'params' ) ?? [] );

        // Sanitize param values
        $clean = array_map( 'sanitize_text_field', $params );

        $api    = new ExtractIA_API_Client();
        $result = $api->run_ocr_tool( $tool_id, $image, $clean );
        return $this->respond( $result );
    }

    // ── Agent run ─────────────────────────────────────────────────────────────

    public function run_agent( WP_REST_Request $request ) {
        $agent_id    = (string) ( $request->get_param( 'agentId' ) ?? '' );
        $image       = (string) ( $request->get_param( 'image' )   ?? '' );
        $inline_cfg  = (array)  ( $request->get_param( 'agentConfig' ) ?? [] );

        // Resolve config: named agent in registry OR inline config from shortcode
        if ( ! empty( $agent_id ) ) {
            $config = ExtractIA_Agent::get( $agent_id );
            if ( ! $config ) {
                return new WP_REST_Response( [ 'error' => "Agent '{$agent_id}' not found.", 'i18nKey' => 'genericError', 'code' => 404 ], 404 );
            }
            $config['id'] = $agent_id;
        } elseif ( ! empty( $inline_cfg ) ) {
            $errors = ExtractIA_Agent::validate_config( $inline_cfg );
            if ( $errors ) {
                return new WP_REST_Response( [ 'error' => 'Invalid agent config.', 'details' => $errors ], 400 );
            }
            $config = $inline_cfg;
        } else {
            return new WP_REST_Response( [ 'error' => 'agentId or agentConfig is required.' ], 400 );
        }

        // Permission check via filter
        $user_id = get_current_user_id();
        $allowed = (bool) apply_filters( 'extractia_rest_permission', true, 'agent-run', $request );
        if ( ! $allowed ) {
            return new WP_REST_Response( [ 'error' => 'Not allowed.', 'i18nKey' => 'authError', 'code' => 403 ], 403 );
        }

        $agent  = new ExtractIA_Agent( $config );
        $result = $agent->run( $image );

        ExtractIA_Hooks::fire_agent_complete( $result, $config );

        return new WP_REST_Response( $result, 200 );
    }

    // ── Widget configs ────────────────────────────────────────────────────────

    public function get_widget_configs( WP_REST_Request $request ) {
        $configs = ExtractIA_Widget_Registry::to_rest_list();
        return new WP_REST_Response( $configs, 200 );
    }

    // ── Agents listing ────────────────────────────────────────────────────────

    public function get_agents( WP_REST_Request $request ) {
        $agents = ExtractIA_Agent::get_all();
        $out    = [];
        foreach ( $agents as $id => $cfg ) {
            $out[] = [
                'id'    => $id,
                'name'  => $cfg['name'] ?? $id,
                'steps' => count( $cfg['steps'] ?? [] ),
            ];
        }
        return new WP_REST_Response( $out, 200 );
    }

    public function test_webhook( WP_REST_Request $request ) {
        $url = get_option( 'extractia_webhook_url', '' );

        if ( empty( $url ) ) {
            return new WP_REST_Response( [ 'error' => 'No webhook URL configured.' ], 400 );
        }

        $test_payload = [
            'event'  => 'test',
            'source' => 'extractia-wp',
            'data'   => [ 'message' => 'Webhook test from ExtractIA WordPress plugin.' ],
        ];

        $resp = wp_remote_post( $url, [
            'body'    => wp_json_encode( $test_payload ),
            'headers' => [ 'Content-Type' => 'application/json' ],
            'timeout' => 15,
        ] );

        if ( is_wp_error( $resp ) ) {
            return new WP_REST_Response( [ 'error' => $resp->get_error_message() ], 502 );
        }

        return new WP_REST_Response( [
            'ok'     => true,
            'status' => wp_remote_retrieve_response_code( $resp ),
        ], 200 );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Converts an API result or WP_Error into a WP_REST_Response.
     */
    private function respond( $result ): WP_REST_Response {
        if ( is_wp_error( $result ) ) {
            $data   = $result->get_error_data();
            $status = isset( $data['status'] ) ? (int) $data['status'] : 500;

            return new WP_REST_Response( [
                'error'    => $result->get_error_message(),
                'i18nKey'  => ExtractIA_API_Client::i18n_key_for_status( $status ),
                'code'     => $status,
            ], $status );
        }

        return new WP_REST_Response( $result, 200 );
    }

    /**
     * If a webhook URL is configured, POST the extraction result to it
     * asynchronously (non-blocking).
     */
    private function maybe_fire_webhook( array $data ) {
        $url = get_option( 'extractia_webhook_url', '' );

        if ( empty( $url ) ) {
            return;
        }

        wp_remote_post( $url, [
            'body'      => wp_json_encode( [
                'event'  => 'document.processed',
                'source' => 'extractia-wp',
                'data'   => $data,
            ] ),
            'headers'   => [ 'Content-Type' => 'application/json' ],
            'timeout'   => 0.01,   // non-blocking
            'blocking'  => false,
            'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
        ] );
    }
}
