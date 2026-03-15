<?php
/**
 * ExtractIA — Shortcodes
 *
 * Available shortcodes:
 *
 *  [extractia_upload]         — Full document upload & extraction widget.
 *  [extractia_docs]           — List of extracted documents for a template.
 *  [extractia_tool]           — Run a single OCR tool (public-facing).
 *  [extractia_usage]          — Usage / quota meter.
 *
 * All shortcodes output escaped HTML and pass data through the REST proxy,
 * so the API key is never exposed to the browser.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ExtractIA_Shortcodes {

    public function __construct() {
        add_shortcode( 'extractia_upload', [ $this, 'upload_widget' ] );
        add_shortcode( 'extractia_docs',   [ $this, 'docs_list' ] );
        add_shortcode( 'extractia_tool',   [ $this, 'ocr_tool' ] );
        add_shortcode( 'extractia_usage',  [ $this, 'usage_meter' ] );
        add_shortcode( 'extractia_agent',  [ $this, 'agent_widget' ] );
    }

    // ── [extractia_upload] ────────────────────────────────────────────────────
    //
    // Attributes:
    //   template     — pre-select a template ID (overrides global setting)
    //   hide_selector — "true" to hide the template dropdown
    //   multipage    — "false" to force single-page mode
    //   show_summary — "false" to hide the AI summary button
    //   class        — extra CSS class on the wrapper
    //   title        — widget heading text
    //   button_text  — label for the process button

    public function upload_widget( $atts ) {
        $a = shortcode_atts( [
            'template'      => get_option( 'extractia_default_template', '' ),
            'hide_selector' => 'false',
            'multipage'     => get_option( 'extractia_allow_multipage', '1' ) ? 'true' : 'false',
            'show_summary'  => get_option( 'extractia_show_summary', '1' ) ? 'true' : 'false',
            'class'         => get_option( 'extractia_custom_css_class', '' ),
            'title'         => '',
            'button_text'   => '',
            'config'        => '',   // named widget config slug from ExtractIA_Widget_Registry
        ], $atts, 'extractia_upload' );

        // Merge named widget config (lower priority than explicit attrs)
        if ( ! empty( $a['config'] ) ) {
            $preset = ExtractIA_Widget_Registry::to_shortcode_atts( $a['config'] );
            if ( ! empty( $preset ) ) {
                // Only override defaults, not explicitly passed attrs
                $explicit_keys = array_keys( (array) $atts );
                foreach ( $preset as $key => $val ) {
                    if ( ! in_array( $key, $explicit_keys, true ) ) {
                        $a[ $key ] = $val;
                    }
                }
            }
        }

        $a = apply_filters( 'extractia_shortcode_atts', $a, 'extractia_upload' );

        // Fetch templates for the selector (server-side, cached)
        $api       = new ExtractIA_API_Client();
        $templates = $api->get_templates();

        if ( is_wp_error( $templates ) ) {
            return '<p class="extractia-error">'
                . esc_html__( 'Could not load ExtractIA templates. Please check your API settings.', 'extractia-wp' )
                . '</p>';
        }

        ob_start();
        $extra_class = sanitize_html_class( $a['class'] );
        ?>
        <div class="extractia-widget <?php echo esc_attr( $extra_class ); ?>"
             data-template="<?php echo esc_attr( $a['template'] ); ?>"
             data-hide-selector="<?php echo esc_attr( $a['hide_selector'] ); ?>"
             data-multipage="<?php echo esc_attr( $a['multipage'] ); ?>"
             data-show-summary="<?php echo esc_attr( $a['show_summary'] ); ?>"
             data-button-text="<?php echo esc_attr( $a['button_text'] ); ?>">

            <?php if ( $a['title'] ) : ?>
                <h3 class="extractia-widget__title"><?php echo esc_html( $a['title'] ); ?></h3>
            <?php endif; ?>

            <!-- Step 1: Template selector -->
            <?php if ( $a['hide_selector'] !== 'true' ) : ?>
            <div class="extractia-step extractia-step--template">
                <label class="extractia-label" for="extractia-template-select-<?php echo uniqid(); ?>">
                    <?php esc_html_e( 'Form template', 'extractia-wp' ); ?>
                </label>
                <select class="extractia-template-select">
                    <option value=""><?php esc_html_e( '— Select a template —', 'extractia-wp' ); ?></option>
                    <?php foreach ( (array) $templates as $tpl ) : ?>
                        <option value="<?php echo esc_attr( $tpl['id'] ); ?>"
                            <?php selected( $a['template'], $tpl['id'] ); ?>>
                            <?php echo esc_html( $tpl['label'] ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <!-- Step 2: Image capture -->
            <div class="extractia-step extractia-step--capture">
                <div class="extractia-dropzone" tabindex="0" role="button"
                     aria-label="<?php esc_attr_e( 'Drop document here or click to browse', 'extractia-wp' ); ?>">
                    <div class="extractia-dropzone__icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14 2 14 8 20 8"/>
                            <line x1="12" y1="18" x2="12" y2="12"/>
                            <line x1="9" y1="15" x2="15" y2="15"/>
                        </svg>
                    </div>
                    <p class="extractia-dropzone__text">
                        <?php esc_html_e( 'Drop your document here or click to browse', 'extractia-wp' ); ?>
                    </p>
                    <p class="extractia-dropzone__sub">
                        <?php esc_html_e( 'JPG, PNG, WEBP, PDF — max', 'extractia-wp' ); ?>
                        <?php echo (int) get_option( 'extractia_max_file_size_mb', 5 ); ?> MB
                    </p>
                    <input type="file" class="extractia-file-input" accept="image/jpeg,image/png,image/webp,application/pdf" style="display:none;" />
                </div>

                <div class="extractia-camera-btn-wrap">
                    <button type="button" class="extractia-btn extractia-btn--secondary extractia-camera-btn">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                        <?php esc_html_e( 'Use camera', 'extractia-wp' ); ?>
                    </button>
                    <video class="extractia-camera-preview" style="display:none;" autoplay playsinline></video>
                    <button type="button" class="extractia-btn extractia-btn--primary extractia-snap-btn" style="display:none;">
                        <?php esc_html_e( 'Capture', 'extractia-wp' ); ?>
                    </button>
                </div>

                <!-- Thumbnail strip -->
                <div class="extractia-pages-strip"></div>

                <?php if ( $a['multipage'] !== 'false' ) : ?>
                <button type="button" class="extractia-btn extractia-btn--ghost extractia-add-page-btn" style="display:none;">
                    + <?php esc_html_e( 'Add page', 'extractia-wp' ); ?>
                </button>
                <?php endif; ?>
            </div>

            <!-- Process button -->
            <div class="extractia-step extractia-step--action">
                <button type="button" class="extractia-btn extractia-btn--primary extractia-process-btn" disabled>
                    <?php echo esc_html( $a['button_text'] ?: __( 'Process document', 'extractia-wp' ) ); ?>
                </button>
            </div>

            <!-- Step 3: Progress -->
            <div class="extractia-step extractia-step--progress" style="display:none;">
                <div class="extractia-spinner"></div>
                <p class="extractia-progress-text"><?php esc_html_e( 'Processing…', 'extractia-wp' ); ?></p>
            </div>

            <!-- Step 4: Results -->
            <div class="extractia-step extractia-step--results" style="display:none;">
                <div class="extractia-results-header">
                    <span class="extractia-results-title"><?php esc_html_e( 'Extraction complete', 'extractia-wp' ); ?></span>
                    <div class="extractia-results-actions">
                        <?php if ( $a['show_summary'] !== 'false' ) : ?>
                        <button type="button" class="extractia-btn extractia-btn--ghost extractia-summary-btn">
                            ✨ <?php esc_html_e( 'Generate AI summary', 'extractia-wp' ); ?>
                        </button>
                        <?php endif; ?>
                        <button type="button" class="extractia-btn extractia-btn--ghost extractia-copy-btn">
                            <?php esc_html_e( 'Copy JSON', 'extractia-wp' ); ?>
                        </button>
                        <button type="button" class="extractia-btn extractia-btn--ghost extractia-csv-btn">
                            <?php esc_html_e( 'Download CSV', 'extractia-wp' ); ?>
                        </button>
                    </div>
                </div>
                <div class="extractia-summary-box" style="display:none;"></div>
                <div class="extractia-fields-table"></div>
                <button type="button" class="extractia-btn extractia-btn--ghost extractia-reset-btn">
                    ↺ <?php esc_html_e( 'Start over', 'extractia-wp' ); ?>
                </button>
            </div>

            <!-- Error banner -->
            <div class="extractia-error-banner" style="display:none;" role="alert"></div>

            <!-- Usage bar -->
            <?php if ( get_option( 'extractia_show_usage_bar', '1' ) ) : ?>
            <div class="extractia-usage-bar-wrap" style="display:none;">
                <div class="extractia-usage-bar">
                    <div class="extractia-usage-bar__fill" style="width:0%;"></div>
                </div>
                <span class="extractia-usage-bar__label"></span>
            </div>
            <?php endif; ?>

            <!-- Hidden camera canvas -->
            <canvas class="extractia-camera-canvas" style="display:none;"></canvas>
        </div>
        <?php
        return ob_get_clean();
    }

    // ── [extractia_docs] ──────────────────────────────────────────────────────
    //
    // Attributes:
    //   template  — required, template ID
    //   limit     — number of documents to show (default 10)
    //   fields    — comma-separated field labels to display as columns

    public function docs_list( $atts ) {
        $a = shortcode_atts( [
            'template' => get_option( 'extractia_default_template', '' ),
            'limit'    => 10,
            'fields'   => get_option( 'extractia_result_fields', '' ),
        ], $atts, 'extractia_docs' );

        if ( empty( $a['template'] ) ) {
            return '<p class="extractia-error">' . esc_html__( 'Please specify a template ID via the template="…" attribute.', 'extractia-wp' ) . '</p>';
        }

        $api  = new ExtractIA_API_Client();
        $docs = $api->get_documents( $a['template'], 0, (int) $a['limit'] );

        if ( is_wp_error( $docs ) ) {
            return '<p class="extractia-error">' . esc_html( $docs->get_error_message() ) . '</p>';
        }

        $items = $docs['content'] ?? [];

        if ( empty( $items ) ) {
            return '<p class="extractia-empty">' . esc_html__( 'No documents found.', 'extractia-wp' ) . '</p>';
        }

        // Determine columns
        $field_filter = array_filter( array_map( 'trim', explode( ',', $a['fields'] ) ) );
        $first        = (array) ( $items[0]['data'] ?? [] );
        $all_cols     = empty( $field_filter ) ? array_keys( $first ) : $field_filter;

        ob_start();
        ?>
        <div class="extractia-docs-list">
            <table class="extractia-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Date', 'extractia-wp' ); ?></th>
                        <?php foreach ( $all_cols as $col ) : ?>
                            <th><?php echo esc_html( $col ); ?></th>
                        <?php endforeach; ?>
                        <th><?php esc_html_e( 'Status', 'extractia-wp' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $items as $doc ) : ?>
                    <tr>
                        <td><?php echo esc_html( isset( $doc['uploadedAt'] ) ? date_i18n( get_option( 'date_format' ), strtotime( $doc['uploadedAt'] ) ) : '—' ); ?></td>
                        <?php foreach ( $all_cols as $col ) : ?>
                            <td><?php echo esc_html( $doc['data'][ $col ] ?? '—' ); ?></td>
                        <?php endforeach; ?>
                        <td>
                            <span class="extractia-status extractia-status--<?php echo esc_attr( strtolower( $doc['status'] ?? 'pending' ) ); ?>">
                                <?php echo esc_html( $doc['status'] ?? 'PENDING' ); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    // ── [extractia_tool] ──────────────────────────────────────────────────────
    //
    // Attributes:
    //   id     — OCR tool config ID
    //   title  — widget title (defaults to tool name)
    //   class  — extra CSS class

    public function ocr_tool( $atts ) {
        $a = shortcode_atts( [
            'id'    => '',
            'title' => '',
            'class' => '',
        ], $atts, 'extractia_tool' );

        if ( empty( $a['id'] ) ) {
            return '<p class="extractia-error">' . esc_html__( 'Please specify an OCR tool id="…" attribute.', 'extractia-wp' ) . '</p>';
        }

        // Fetch tool to get name and parameterDefinitions
        $api   = new ExtractIA_API_Client();
        $tools = $api->get_ocr_tools();

        $tool = null;
        if ( ! is_wp_error( $tools ) ) {
            foreach ( (array) $tools as $t ) {
                if ( ( $t['id'] ?? '' ) === $a['id'] ) {
                    $tool = $t;
                    break;
                }
            }
        }

        $title     = $a['title'] ?: ( $tool['name'] ?? __( 'OCR Tool', 'extractia-wp' ) );
        $params    = $tool['parameterDefinitions'] ?? [];
        $out_type  = $tool['outputType'] ?? 'TEXT';

        ob_start();
        ?>
        <div class="extractia-ocr-tool <?php echo esc_attr( sanitize_html_class( $a['class'] ) ); ?>"
             data-tool-id="<?php echo esc_attr( $a['id'] ); ?>"
             data-output-type="<?php echo esc_attr( $out_type ); ?>">

            <?php if ( $title ) : ?>
                <h3 class="extractia-label"><?php echo esc_html( $title ); ?></h3>
            <?php endif; ?>

            <!-- Image drop / capture -->
            <div class="extractia-dropzone extractia-dropzone--sm" tabindex="0" role="button">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                <p><?php esc_html_e( 'Drop or click to add image', 'extractia-wp' ); ?></p>
                <input type="file" class="extractia-file-input" accept="image/jpeg,image/png,image/webp" style="display:none;" />
            </div>
            <div class="extractia-ocr-preview-wrap"></div>

            <!-- Dynamic parameters -->
            <?php foreach ( $params as $param ) : ?>
            <div class="extractia-ocr-param" style="margin-top:10px;">
                <label class="extractia-label">
                    <?php echo esc_html( $param['label'] ?? 'Parameter ' . $param['key'] ); ?>
                </label>
                <input type="text"
                       class="extractia-input extractia-ocr-param__input"
                       data-param-key="<?php echo esc_attr( $param['key'] ); ?>"
                       maxlength="<?php echo esc_attr( $param['maxChars'] ?? 200 ); ?>"
                       placeholder="<?php echo esc_attr( $param['description'] ?? '' ); ?>" />
            </div>
            <?php endforeach; ?>

            <button type="button" class="extractia-btn extractia-btn--primary extractia-ocr-run-btn" style="margin-top:12px;" disabled>
                <?php esc_html_e( 'Analyze', 'extractia-wp' ); ?>
            </button>

            <div class="extractia-ocr-result" style="display:none;">
                <strong><?php esc_html_e( 'Result:', 'extractia-wp' ); ?></strong>
                <span class="extractia-ocr-result__answer"></span>
                <p class="extractia-ocr-result__explanation"></p>
            </div>

            <div class="extractia-error-banner" style="display:none;" role="alert"></div>
            <div class="extractia-spinner" style="display:none;"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ── [extractia_usage] ─────────────────────────────────────────────────────

    public function usage_meter( $atts ) {
        $api     = new ExtractIA_API_Client();
        $profile = $api->get_profile();
        $credits = $api->get_credits();

        if ( is_wp_error( $profile ) ) {
            return '';
        }

        $used    = (int) ( $profile['documentsUsed']  ?? 0 );
        $limit   = (int) ( $profile['documentsLimit'] ?? 0 );
        $pct     = $limit > 0 ? min( 100, round( $used / $limit * 100 ) ) : 0;
        $color   = $pct >= 90 ? '#d63638' : ( $pct >= 70 ? '#dba617' : '#00a32a' );
        $ai_bal  = is_wp_error( $credits ) ? null : ( $credits['totalBalance'] ?? null );

        ob_start();
        ?>
        <div class="extractia-usage-widget">
            <div class="extractia-usage-widget__row">
                <span><?php esc_html_e( 'Documents', 'extractia-wp' ); ?></span>
                <strong><?php echo esc_html( $used . ' / ' . ( $limit > 0 ? $limit : '∞' ) ); ?></strong>
            </div>
            <?php if ( $limit > 0 ) : ?>
            <div class="extractia-usage-bar" style="margin:8px 0;">
                <div class="extractia-usage-bar__fill" style="width:<?php echo esc_attr( $pct ); ?>%;background:<?php echo esc_attr( $color ); ?>;"></div>
            </div>
            <?php endif; ?>
            <?php if ( $ai_bal !== null ) : ?>
            <div class="extractia-usage-widget__row" style="margin-top:8px;">
                <span><?php esc_html_e( 'AI Credits', 'extractia-wp' ); ?></span>
                <strong><?php echo esc_html( $ai_bal ); ?></strong>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    // ── [extractia_agent] ─────────────────────────────────────────────────────
    //
    // Renders a multi-step AI agent widget.
    //
    // Attributes:
    //   id    — registered agent ID (from ExtractIA_Agent registry)
    //   class — extra CSS class on the wrapper
    //   title — override agent display name

    public function agent_widget( $atts ) {
        $a = shortcode_atts( [
            'id'    => '',
            'class' => '',
            'title' => '',
        ], $atts, 'extractia_agent' );

        if ( empty( $a['id'] ) ) {
            return '<p class="extractia-error">'
                . esc_html__( 'extractia_agent: "id" attribute is required.', 'extractia-wp' )
                . '</p>';
        }

        $agent = ExtractIA_Agent::get( $a['id'] );

        if ( ! $agent ) {
            return '<p class="extractia-error">'
                . esc_html__( 'Agent not found.', 'extractia-wp' )
                . '</p>';
        }

        $config_json = wp_json_encode( $agent );
        $display     = esc_html( $a['title'] ?: ( $agent['name'] ?? $a['id'] ) );

        ob_start();
        ?>
        <div class="extractia-agent-widget <?php echo esc_attr( sanitize_html_class( $a['class'] ) ); ?>"
             data-agent-id="<?php echo esc_attr( $a['id'] ); ?>"
             data-agent-config="<?php echo esc_attr( $config_json ); ?>">

            <h3 class="extractia-agent__title"><?php echo $display; ?></h3>

            <!-- Step progress pills (populated by JS) -->
            <div class="extractia-agent__steps"></div>

            <!-- Dropzone -->
            <div class="extractia-dropzone extractia-agent__dropzone" tabindex="0" role="button">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="1.5">
                    <rect x="3" y="3" width="18" height="18" rx="2"/>
                    <circle cx="8.5" cy="8.5" r="1.5"/>
                    <polyline points="21 15 16 10 5 21"/>
                </svg>
                <p><?php esc_html_e( 'Drop document here or click to browse', 'extractia-wp' ); ?></p>
                <input type="file" class="extractia-file-input"
                       accept="image/jpeg,image/png,image/webp,application/pdf"
                       style="display:none;" />
            </div>

            <!-- Preview thumbnail -->
            <div class="extractia-agent__preview-wrap"></div>

            <!-- Run button -->
            <button type="button"
                    class="extractia-btn extractia-btn--primary extractia-agent__run-btn"
                    disabled>
                <?php esc_html_e( 'Run Agent', 'extractia-wp' ); ?>
            </button>

            <!-- Progress indicator (shown during run) -->
            <div class="extractia-agent__progress" style="display:none;" aria-live="polite">
                <span class="extractia-spinner"></span>
                <span class="extractia-agent__current-step-label"></span>
            </div>

            <!-- Result area -->
            <div class="extractia-agent__result" style="display:none;"></div>

            <!-- Error banner -->
            <div class="extractia-error-banner" style="display:none;" role="alert"></div>
        </div>
        <?php
        $a = apply_filters( 'extractia_shortcode_atts', $a, 'extractia_agent' );
        return ob_get_clean();
    }
}
