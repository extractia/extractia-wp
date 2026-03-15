<?php
/**
 * ExtractIA PHP API Client
 *
 * Server-side HTTP wrapper around the ExtractIA public REST API.
 * Uses WordPress HTTP functions (wp_remote_*) so all WP proxy / SSL
 * settings are respected automatically.
 *
 * The API key is read from wp_options — it is NEVER sent to the browser.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ExtractIA_API_Client {

    /** @var string */
    private $api_key;

    /** @var string */
    private $base_url;

    /** @var int  HTTP timeout in seconds */
    private $timeout = 60;

    public function __construct( $api_key = null ) {
        $this->api_key  = $api_key ?? get_option( EXTRACTIA_OPTION_KEY, '' );
        $this->base_url = EXTRACTIA_API_BASE;
    }

    // ── Generic HTTP helpers ──────────────────────────────────────────────────

    /**
     * @return array|WP_Error  Raw wp_remote_* response or error object.
     */
    private function request( string $method, string $endpoint, array $body = [], array $params = [] ) {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'extractia_no_key', __( 'ExtractIA API key is not configured.', 'extractia-wp' ) );
        }

        $url = trailingslashit( $this->base_url ) . ltrim( $endpoint, '/' );

        if ( ! empty( $params ) ) {
            $url = add_query_arg( $params, $url );
        }

        $args = [
            'method'  => strtoupper( $method ),
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'timeout' => $this->timeout,
        ];

        if ( ! empty( $body ) && in_array( $args['method'], [ 'POST', 'PUT', 'PATCH' ], true ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        return wp_remote_request( $url, $args );
    }

    /**
     * Parse a wp_remote_* response into a PHP value or WP_Error.
     *
     * @param array|WP_Error $response
     * @return mixed|WP_Error
     */
    private function parse( $response ) {
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code >= 200 && $code < 300 ) {
            return $data ?? true;
        }

        $message = isset( $data['error'] ) ? $data['error'] : wp_remote_retrieve_response_message( $response );

        return new WP_Error(
            'extractia_api_' . $code,
            $message,
            [ 'status' => $code, 'body' => $data ]
        );
    }

    private function get( string $endpoint, array $params = [] ) {
        return $this->parse( $this->request( 'GET', $endpoint, [], $params ) );
    }

    private function post( string $endpoint, array $body = [] ) {
        return $this->parse( $this->request( 'POST', $endpoint, $body ) );
    }

    private function put( string $endpoint, array $body = [] ) {
        return $this->parse( $this->request( 'PUT', $endpoint, $body ) );
    }

    private function delete( string $endpoint ) {
        return $this->parse( $this->request( 'DELETE', $endpoint ) );
    }

    // ── Profile / Usage ───────────────────────────────────────────────────────

    /**
     * Returns the authenticated user's profile (plan, quota, email, etc.).
     * Result is cached for 5 minutes via transient.
     */
    public function get_profile() {
        $cached = get_transient( 'extractia_profile_cache' );
        if ( $cached !== false ) {
            return $cached;
        }

        $result = $this->get( 'me' );

        if ( ! is_wp_error( $result ) ) {
            set_transient( 'extractia_profile_cache', $result, 5 * MINUTE_IN_SECONDS );
        }

        return $result;
    }

    /** Returns AI credits balance: monthlyBalance, addonBalance, totalBalance. */
    public function get_credits() {
        return $this->get( 'me/credits' );
    }

    /** Returns document processing history (paginated). */
    public function get_document_history( int $page = 0, int $size = 20 ) {
        return $this->get( 'me/documents/history', [ 'page' => $page, 'size' => min( $size, 100 ) ] );
    }

    // ── Templates ─────────────────────────────────────────────────────────────

    /**
     * Returns all templates.  Cached for 2 minutes.
     */
    public function get_templates() {
        $cached = get_transient( 'extractia_templates_cache' );
        if ( $cached !== false ) {
            return $cached;
        }

        $result = $this->get( 'templates' );

        if ( ! is_wp_error( $result ) ) {
            set_transient( 'extractia_templates_cache', $result, 2 * MINUTE_IN_SECONDS );
        }

        return $result;
    }

    /** Returns a single template by ID. */
    public function get_template( string $id ) {
        return $this->get( "templates/{$id}" );
    }

    // ── Documents ─────────────────────────────────────────────────────────────

    /**
     * Processes a single base64 image against a template.
     *
     * @param string $template_id
     * @param string $base64_image  With or without data-URI prefix.
     * @return array|WP_Error
     */
    public function process_image( string $template_id, string $base64_image ) {
        return $this->post( "templates/{$template_id}/process", [
            'image' => $base64_image,
        ] );
    }

    /**
     * Processes multiple base64 images as a multipage document.
     *
     * @param string   $template_id
     * @param string[] $images  Array of base64 strings.
     * @return array|WP_Error
     */
    public function process_multipage( string $template_id, array $images ) {
        return $this->post( "templates/{$template_id}/process-multipage", [
            'images' => $images,
        ] );
    }

    /**
     * Returns paginated documents for a template.
     *
     * @param string $template_id
     * @param int    $page   Zero-based index.
     * @param int    $size   Page size (default 10).
     * @return array|WP_Error  { content: [], totalPages: int }
     */
    public function get_documents( string $template_id, int $page = 0, int $size = 10 ) {
        return $this->get( "templates/{$template_id}/documents", [
            'index'        => $page,
            'includeImage' => 0,
        ] );
    }

    /** Returns the N most-recent documents across all templates (max 50). */
    public function get_recent_documents( int $size = 10 ) {
        return $this->get( 'documents/recent', [ 'size' => min( $size, 50 ) ] );
    }

    /** Generates an AI summary for a document. */
    public function get_document_summary( string $doc_id ) {
        return $this->post( "documents/{$doc_id}/summary" );
    }

    // ── OCR Tools ─────────────────────────────────────────────────────────────

    /**
     * Returns all OCR tool configurations.  Cached for 5 minutes.
     */
    public function get_ocr_tools() {
        $cached = get_transient( 'extractia_ocr_tools_cache' );
        if ( $cached !== false ) {
            return $cached;
        }

        $result = $this->get( 'ocr-tools' );

        if ( ! is_wp_error( $result ) ) {
            set_transient( 'extractia_ocr_tools_cache', $result, 5 * MINUTE_IN_SECONDS );
        }

        return $result;
    }

    /**
     * Runs an OCR tool against a base64 image.
     *
     * @param string $tool_id
     * @param string $base64_image
     * @param array  $params  Optional map of placeholder key → value.
     * @return array|WP_Error  { answer: string, explanation: string }
     */
    public function run_ocr_tool( string $tool_id, string $base64_image, array $params = [] ) {
        $body = [ 'image' => $base64_image ];

        if ( ! empty( $params ) ) {
            $body['params'] = $params;
        }

        return $this->post( "ocr-tools/{$tool_id}/run", $body );
    }

    // ── Subusers ──────────────────────────────────────────────────────────────

    /** Returns all subusers for the account. */
    public function get_subusers() {
        return $this->get( 'me/subusers' );
    }

    /**
     * Creates a subuser.
     *
     * @param string   $username
     * @param string   $password
     * @param string[] $permissions  e.g. ['upload','view']
     * @param string[] $allowed_form_ids  Optional restriction list.
     */
    public function create_subuser( string $username, string $password, array $permissions, array $allowed_form_ids = [] ) {
        $body = compact( 'username', 'password', 'permissions' );

        if ( ! empty( $allowed_form_ids ) ) {
            $body['allowedFormIds'] = $allowed_form_ids;
        }

        return $this->post( 'me/subusers', $body );
    }

    /**
     * Updates a subuser's permissions, allowed forms, and/or password.
     *
     * @param string $username
     * @param array  $updates  Partial: permissions, allowedFormIds, password.
     */
    public function update_subuser( string $username, array $updates ) {
        return $this->put( "me/subusers/{$username}", $updates );
    }

    /** Deletes a subuser (their token is immediately revoked). */
    public function delete_subuser( string $username ) {
        return $this->delete( "me/subusers/{$username}" );
    }

    /** Toggles the suspended state of a subuser. */
    public function toggle_suspend_subuser( string $username ) {
        return $this->put( "me/subusers/{$username}/suspend" );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Returns a human-readable WP_Error message suitable for JSON responses.
     *
     * @param WP_Error $error
     * @return array  { error: string, code: int }
     */
    public static function format_error( WP_Error $error ): array {
        $data   = $error->get_error_data();
        $status = isset( $data['status'] ) ? (int) $data['status'] : 500;

        return [
            'error'  => $error->get_error_message(),
            'code'   => $status,
        ];
    }

    /**
     * Maps an HTTP status code to a user-facing i18n error key
     * (matches ExtractIAConfig.i18n keys localised in extractia-wp.php).
     */
    public static function i18n_key_for_status( int $status ): string {
        switch ( $status ) {
            case 401: return 'authError';
            case 402: return 'quotaExceeded';
            case 403: return 'tierError';
            case 429: return 'rateLimited';
            case 404: return 'genericError';
            default:  return 'genericError';
        }
    }
}
