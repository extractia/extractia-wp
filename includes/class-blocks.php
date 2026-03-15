<?php
/**
 * ExtractIA — Gutenberg Blocks
 *
 * Registers server-side Gutenberg blocks that delegate rendering to the
 * same shortcode callbacks, keeping all output logic in one place.
 *
 * Blocks:
 *   extractia/upload         — Upload & extraction widget
 *   extractia/document-list  — Document list table
 *   extractia/ocr-tool       — OCR tool widget
 *   extractia/usage-meter    — Quota & credits meter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ExtractIA_Blocks {

    public function __construct() {
        add_action( 'init', [ $this, 'register_blocks' ] );
        add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_assets' ] );
    }

    public function register_blocks() {
        if ( ! function_exists( 'register_block_type' ) ) {
            return;
        }

        // ── extractia/upload ──────────────────────────────────────────────
        register_block_type( 'extractia/upload', [
            'title'           => __( 'ExtractIA Upload', 'extractia-wp' ),
            'description'     => __( 'Drag-and-drop document upload and AI extraction widget.', 'extractia-wp' ),
            'category'        => 'widgets',
            'icon'            => 'media-document',
            'attributes'      => [
                'template'     => [ 'type' => 'string', 'default' => '' ],
                'hideSelector' => [ 'type' => 'boolean', 'default' => false ],
                'multipage'    => [ 'type' => 'boolean', 'default' => true ],
                'showSummary'  => [ 'type' => 'boolean', 'default' => true ],
                'className'    => [ 'type' => 'string',  'default' => '' ],
                'title'        => [ 'type' => 'string',  'default' => '' ],
                'buttonText'   => [ 'type' => 'string',  'default' => '' ],
            ],
            'render_callback' => [ $this, 'render_upload' ],
            'supports'        => [ 'align' => [ 'wide', 'full' ] ],
        ] );

        // ── extractia/document-list ───────────────────────────────────────
        register_block_type( 'extractia/document-list', [
            'title'           => __( 'ExtractIA Document List', 'extractia-wp' ),
            'description'     => __( 'Display extracted documents for a template.', 'extractia-wp' ),
            'category'        => 'widgets',
            'icon'            => 'list-view',
            'attributes'      => [
                'template' => [ 'type' => 'string', 'default' => '' ],
                'limit'    => [ 'type' => 'integer', 'default' => 10 ],
                'fields'   => [ 'type' => 'string',  'default' => '' ],
            ],
            'render_callback' => [ $this, 'render_docs' ],
        ] );

        // ── extractia/ocr-tool ────────────────────────────────────────────
        register_block_type( 'extractia/ocr-tool', [
            'title'           => __( 'ExtractIA OCR Tool', 'extractia-wp' ),
            'description'     => __( 'Drop an image and run a custom AI OCR tool.', 'extractia-wp' ),
            'category'        => 'widgets',
            'icon'            => 'search',
            'attributes'      => [
                'toolId'    => [ 'type' => 'string', 'default' => '' ],
                'title'     => [ 'type' => 'string', 'default' => '' ],
                'className' => [ 'type' => 'string', 'default' => '' ],
            ],
            'render_callback' => [ $this, 'render_ocr_tool' ],
        ] );

        // ── extractia/usage-meter ─────────────────────────────────────────
        register_block_type( 'extractia/usage-meter', [
            'title'           => __( 'ExtractIA Usage Meter', 'extractia-wp' ),
            'description'     => __( 'Displays current document quota and AI credit balance.', 'extractia-wp' ),
            'category'        => 'widgets',
            'icon'            => 'chart-bar',
            'attributes'      => [],
            'render_callback' => [ $this, 'render_usage' ],
        ] );
    }

    // ── Render callbacks ──────────────────────────────────────────────────────

    public function render_upload( array $attrs ): string {
        $sc_attrs = [
            'template'      => $attrs['template']     ?? '',
            'hide_selector' => ( $attrs['hideSelector'] ?? false ) ? 'true' : 'false',
            'multipage'     => ( $attrs['multipage']    ?? true )  ? 'true' : 'false',
            'show_summary'  => ( $attrs['showSummary']  ?? true )  ? 'true' : 'false',
            'class'         => $attrs['className']    ?? '',
            'title'         => $attrs['title']        ?? '',
            'button_text'   => $attrs['buttonText']   ?? '',
        ];
        return ( new ExtractIA_Shortcodes() )->upload_widget( $sc_attrs );
    }

    public function render_docs( array $attrs ): string {
        return ( new ExtractIA_Shortcodes() )->docs_list( [
            'template' => $attrs['template'] ?? '',
            'limit'    => $attrs['limit']    ?? 10,
            'fields'   => $attrs['fields']   ?? '',
        ] );
    }

    public function render_ocr_tool( array $attrs ): string {
        return ( new ExtractIA_Shortcodes() )->ocr_tool( [
            'id'    => $attrs['toolId']    ?? '',
            'title' => $attrs['title']     ?? '',
            'class' => $attrs['className'] ?? '',
        ] );
    }

    public function render_usage( array $attrs ): string {
        return ( new ExtractIA_Shortcodes() )->usage_meter( [] );
    }

    // ── Editor assets ─────────────────────────────────────────────────────────

    public function enqueue_editor_assets() {
        wp_enqueue_script(
            'extractia-blocks-editor',
            EXTRACTIA_PLUGIN_URL . 'admin/js/blocks-editor.js',
            [ 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components' ],
            EXTRACTIA_VERSION,
            true
        );

        wp_localize_script( 'extractia-blocks-editor', 'ExtractIABlocksData', [
            'apiKey'  => ! empty( get_option( EXTRACTIA_OPTION_KEY ) ),
            'restUrl' => esc_url_raw( get_rest_url( null, 'extractia/v1' ) ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
        ] );

        wp_enqueue_style(
            'extractia-blocks-editor',
            EXTRACTIA_PLUGIN_URL . 'admin/css/admin.css',
            [],
            EXTRACTIA_VERSION
        );
    }
}
