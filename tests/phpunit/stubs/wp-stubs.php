<?php
/**
 * Minimal WordPress function stubs for unit testing.
 *
 * Only the functions actually used by plugin code are stubbed here.
 * Keep them in alphabetical order.
 */

// ── Option store ──────────────────────────────────────────────────────────────

$GLOBALS['_extractia_options'] = [];
$GLOBALS['_extractia_transients'] = [];

function get_option( $key, $default = false ) {
    return $GLOBALS['_extractia_options'][ $key ] ?? $default;
}

function update_option( $key, $value, $autoload = null ) {
    $GLOBALS['_extractia_options'][ $key ] = $value;
    return true;
}

function add_option( $key, $value = '', $deprecated = '', $autoload = 'yes' ) {
    if ( ! isset( $GLOBALS['_extractia_options'][ $key ] ) ) {
        $GLOBALS['_extractia_options'][ $key ] = $value;
    }
    return true;
}

function delete_option( $key ) {
    unset( $GLOBALS['_extractia_options'][ $key ] );
    return true;
}

// ── Transients ────────────────────────────────────────────────────────────────

function get_transient( $key ) {
    return $GLOBALS['_extractia_transients'][ $key ] ?? false;
}

function set_transient( $key, $value, $expiry = 0 ) {
    $GLOBALS['_extractia_transients'][ $key ] = $value;
    return true;
}

function delete_transient( $key ) {
    unset( $GLOBALS['_extractia_transients'][ $key ] );
    return true;
}

// ── HTTP ──────────────────────────────────────────────────────────────────────

// Default response can be overridden per-test via $GLOBALS['_wp_remote_response']
$GLOBALS['_wp_remote_response'] = null;

function wp_remote_post( $url, $args = [] ) {
    if ( $GLOBALS['_wp_remote_response'] !== null ) {
        return $GLOBALS['_wp_remote_response'];
    }
    return [ 'response' => [ 'code' => 200, 'message' => 'OK' ], 'body' => '{}' ];
}

function wp_remote_get( $url, $args = [] ) {
    if ( $GLOBALS['_wp_remote_response'] !== null ) {
        return $GLOBALS['_wp_remote_response'];
    }
    return [ 'response' => [ 'code' => 200, 'message' => 'OK' ], 'body' => '[]' ];
}

function wp_remote_retrieve_body( $response ) {
    return $response['body'] ?? '';
}

function wp_remote_retrieve_response_code( $response ) {
    return $response['response']['code'] ?? 200;
}

function is_wp_error( $thing ) {
    return $thing instanceof WP_Error;
}

// ── WP_Error ──────────────────────────────────────────────────────────────────

class WP_Error {
    private string $code;
    private string $message;
    private mixed  $data;

    public function __construct( $code = '', $message = '', $data = '' ) {
        $this->code    = $code;
        $this->message = $message;
        $this->data    = $data;
    }

    public function get_error_code(): string    { return $this->code; }
    public function get_error_message(): string { return $this->message; }
    public function get_error_data(): mixed     { return $this->data; }
}

// ── WP_REST_Request ───────────────────────────────────────────────────────────

class WP_REST_Request {
    private array $params = [];
    private array $headers = [];
    private string $method = 'GET';

    public function __construct( $method = 'GET', $route = '', array $params = [] ) {
        $this->method = $method;
        $this->params = $params;
    }

    public function get_param( $key ) { return $this->params[ $key ] ?? null; }
    public function set_param( $key, $val ) { $this->params[ $key ] = $val; }
    public function get_method(): string { return $this->method; }
    public function get_header( $key ) { return $this->headers[ strtolower($key) ] ?? null; }
    public function set_header( $key, $val ) { $this->headers[ strtolower($key) ] = $val; }
}

// ── WP_REST_Response ──────────────────────────────────────────────────────────

class WP_REST_Response {
    public mixed $data;
    public int   $status;

    public function __construct( $data = null, $status = 200 ) {
        $this->data   = $data;
        $this->status = $status;
    }

    public function get_data(): mixed { return $this->data; }
    public function get_status(): int { return $this->status; }
}

// ── Hooks ─────────────────────────────────────────────────────────────────────

$GLOBALS['_extractia_hooks'] = [];

function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
    $GLOBALS['_extractia_hooks']['actions'][ $tag ][] = compact( 'callback', 'priority', 'accepted_args' );
}

function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
    $GLOBALS['_extractia_hooks']['filters'][ $tag ][] = compact( 'callback', 'priority', 'accepted_args' );
}

function apply_filters( $tag, $value, ...$args ) {
    $filters = $GLOBALS['_extractia_hooks']['filters'][ $tag ] ?? [];
    foreach ( $filters as $item ) {
        $value = call_user_func_array( $item['callback'], array_merge( [ $value ], $args ) );
    }
    return $value;
}

function do_action( $tag, ...$args ) {
    $actions = $GLOBALS['_extractia_hooks']['actions'][ $tag ] ?? [];
    foreach ( $actions as $item ) {
        call_user_func_array( $item['callback'], $args );
    }
}

function add_shortcode( $tag, $callback ) {}
function do_shortcode( $content ) { return $content; }

// ── Misc ──────────────────────────────────────────────────────────────────────

function sanitize_text_field( $str ) { return trim( strip_tags( $str ) ); }
function sanitize_html_class( $class, $fallback = '' ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', $class ); }
function esc_html( $text )  { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text )  { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url )    { return filter_var( $url, FILTER_SANITIZE_URL ) ?: ''; }
function esc_url_raw( $url ){ return $url; }
function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); }
function current_user_can( $cap ) { return true; }
function wp_kses_post( $data ) { return $data; }
function get_rest_url( $blog_id = null, $path = '/' ) { return 'https://example.com/wp-json/' . ltrim( $path, '/' ); }
function wp_create_nonce( $action = -1 ) { return 'test-nonce-' . md5( $action ); }
function register_rest_route( $namespace, $route, $args = [], $override = false ) { return true; }
function register_block_type( $name, $args = [] ) { return true; }
function wp_register_script( $handle, $src, $deps = [], $ver = false, $in_footer = false ) { return true; }
function wp_enqueue_script( $handle, $src = '', $deps = [], $ver = false, $in_footer = false ) {}
function wp_enqueue_style( $handle, $src = '', $deps = [], $ver = false, $media = 'all' ) {}
function wp_localize_script( $handle, $object_name, $l10n ) { return true; }
function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
    $atts = (array) $atts;
    $out  = [];
    foreach ( $pairs as $name => $default ) {
        $out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default;
    }
    return $out;
}
function uniqid( $prefix = '', $more_entropy = false ) { return $prefix . mt_rand(); }
function load_plugin_textdomain( $domain, $deprecated = false, $plugin_rel_path = false ) {}
function plugin_basename( $file ) { return basename( $file ); }
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_dir_url( $file ) { return 'https://example.com/wp-content/plugins/' . basename( dirname( $file ) ) . '/'; }
function register_activation_hook( $file, $callback ) {}
function register_deactivation_hook( $file, $callback ) {}
function is_multisite() { return false; }
function get_sites( $args = [] ) { return []; }
function switch_to_blog( $blog_id ) {}
function restore_current_blog() {}
function ob_start() { \ob_start(); }
function ob_get_clean() { return \ob_get_clean(); }
function __( $text, $domain = 'default' ) { return $text; }
function esc_html__( $text, $domain = 'default' ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function _e( $text, $domain = 'default' ) { echo $text; }
function number_format_i18n( $number, $decimals = 0 ) { return number_format( $number, $decimals ); }
