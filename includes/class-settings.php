<?php
/**
 * ExtractIA — Settings Page
 *
 * Registers and renders the plugin settings in WP Admin → ExtractIA → Settings.
 * Handles API key validation, workflow configuration, and appearance options.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ExtractIA_Settings {

    const MENU_SLUG    = 'extractia-settings';
    const OPTION_GROUP = 'extractia_options';

    public function __construct() {
        add_action( 'admin_menu',    [ $this, 'register_menu' ] );
        add_action( 'admin_init',    [ $this, 'register_settings' ] );
        add_action( 'admin_notices', [ $this, 'api_key_notice' ] );
        add_action( 'admin_post_extractia_test_key', [ $this, 'handle_test_key' ] );
    }

    // ── Menu ──────────────────────────────────────────────────────────────────

    public function register_menu() {
        add_menu_page(
            __( 'ExtractIA', 'extractia-wp' ),
            'ExtractIA',
            'manage_options',
            'extractia',
            [ $this, 'render_dashboard_redirect' ],
            'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="white"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zm-1 1.5L18.5 9H13V3.5zM6 20V4h5v7h7v9H6z"/></svg>' ),
            56
        );

        add_submenu_page( 'extractia', __( 'Dashboard', 'extractia-wp' ),  __( 'Dashboard', 'extractia-wp' ),  'manage_options', 'extractia',           [ 'ExtractIA_Admin_Dashboard', 'render' ] );
        add_submenu_page( 'extractia', __( 'Settings', 'extractia-wp' ),   __( 'Settings', 'extractia-wp' ),   'manage_options', self::MENU_SLUG,        [ $this, 'render' ] );
        add_submenu_page( 'extractia', __( 'OCR Tools', 'extractia-wp' ),  __( 'OCR Tools', 'extractia-wp' ),  'manage_options', 'extractia-ocr-tools',   [ 'ExtractIA_Admin_Dashboard', 'render_ocr_tools' ] );
        add_submenu_page( 'extractia', __( 'Sub-Users', 'extractia-wp' ),  __( 'Sub-Users', 'extractia-wp' ),  'manage_options', 'extractia-subusers',    [ 'ExtractIA_Admin_Dashboard', 'render_subusers' ] );
    }

    /** The top-level "extractia" slug just shows the dashboard. */
    public function render_dashboard_redirect() {
        ExtractIA_Admin_Dashboard::render();
    }

    // ── Settings registration ─────────────────────────────────────────────────

    public function register_settings() {
        register_setting( self::OPTION_GROUP, 'extractia_api_key',          [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( self::OPTION_GROUP, 'extractia_default_template', [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( self::OPTION_GROUP, 'extractia_workflow_mode',    [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( self::OPTION_GROUP, 'extractia_redirect_url',     [ 'sanitize_callback' => 'esc_url_raw' ] );
        register_setting( self::OPTION_GROUP, 'extractia_webhook_url',      [ 'sanitize_callback' => 'esc_url_raw' ] );
        register_setting( self::OPTION_GROUP, 'extractia_show_summary',     [ 'sanitize_callback' => 'absint' ] );
        register_setting( self::OPTION_GROUP, 'extractia_allow_multipage',  [ 'sanitize_callback' => 'absint' ] );
        register_setting( self::OPTION_GROUP, 'extractia_max_file_size_mb', [ 'sanitize_callback' => 'absint' ] );
        register_setting( self::OPTION_GROUP, 'extractia_result_fields',    [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( self::OPTION_GROUP, 'extractia_custom_css_class', [ 'sanitize_callback' => 'sanitize_html_class' ] );
        register_setting( self::OPTION_GROUP, 'extractia_show_usage_bar',   [ 'sanitize_callback' => 'absint' ] );

        // ── Section: API ──
        add_settings_section( 'extractia_api',      __( 'API Connection', 'extractia-wp' ),  [ $this, 'section_api_cb' ],      self::MENU_SLUG );
        add_settings_field( 'extractia_api_key',    __( 'API Key', 'extractia-wp' ),         [ $this, 'field_api_key' ],        self::MENU_SLUG, 'extractia_api' );

        // ── Section: Workflow ──
        add_settings_section( 'extractia_workflow', __( 'Upload Workflow', 'extractia-wp' ), [ $this, 'section_workflow_cb' ],  self::MENU_SLUG );
        add_settings_field( 'extractia_default_template', __( 'Default Template', 'extractia-wp' ), [ $this, 'field_default_template' ], self::MENU_SLUG, 'extractia_workflow' );
        add_settings_field( 'extractia_workflow_mode',    __( 'After Processing', 'extractia-wp' ),  [ $this, 'field_workflow_mode' ],    self::MENU_SLUG, 'extractia_workflow' );
        add_settings_field( 'extractia_redirect_url',     __( 'Redirect URL', 'extractia-wp' ),      [ $this, 'field_redirect_url' ],     self::MENU_SLUG, 'extractia_workflow' );
        add_settings_field( 'extractia_webhook_url',      __( 'Webhook URL', 'extractia-wp' ),        [ $this, 'field_webhook_url' ],      self::MENU_SLUG, 'extractia_workflow' );
        add_settings_field( 'extractia_show_summary',     __( 'AI Summary button', 'extractia-wp' ),  [ $this, 'field_show_summary' ],     self::MENU_SLUG, 'extractia_workflow' );
        add_settings_field( 'extractia_allow_multipage',  __( 'Allow multi-page', 'extractia-wp' ),   [ $this, 'field_allow_multipage' ],  self::MENU_SLUG, 'extractia_workflow' );
        add_settings_field( 'extractia_max_file_size_mb', __( 'Max file size (MB)', 'extractia-wp' ), [ $this, 'field_max_file_size' ],    self::MENU_SLUG, 'extractia_workflow' );

        // ── Section: Appearance ──
        add_settings_section( 'extractia_appearance', __( 'Appearance', 'extractia-wp' ), [ $this, 'section_appearance_cb' ], self::MENU_SLUG );
        add_settings_field( 'extractia_result_fields',    __( 'Fields to display', 'extractia-wp' ),   [ $this, 'field_result_fields' ],   self::MENU_SLUG, 'extractia_appearance' );
        add_settings_field( 'extractia_custom_css_class', __( 'Custom CSS class', 'extractia-wp' ),     [ $this, 'field_custom_css' ],      self::MENU_SLUG, 'extractia_appearance' );
        add_settings_field( 'extractia_show_usage_bar',   __( 'Show usage bar', 'extractia-wp' ),       [ $this, 'field_show_usage_bar' ],  self::MENU_SLUG, 'extractia_appearance' );
    }

    // ── Section callbacks ─────────────────────────────────────────────────────

    public function section_api_cb() {
        echo '<p>' . sprintf(
            /* translators: %s = link to extractia.info */
            __( 'Enter your API key from <a href="%s" target="_blank">extractia.info → API Keys</a>. The key is stored server-side and never exposed to the browser.', 'extractia-wp' ),
            'https://extractia.info'
        ) . '</p>';
    }

    public function section_workflow_cb() {
        echo '<p>' . __( 'Control what happens after a document is processed.', 'extractia-wp' ) . '</p>';
    }

    public function section_appearance_cb() {
        echo '<p>' . __( 'Customise the upload widget appearance.', 'extractia-wp' ) . '</p>';
    }

    // ── Field callbacks ───────────────────────────────────────────────────────

    public function field_api_key() {
        $val = get_option( 'extractia_api_key', '' );
        $masked = $val ? substr( $val, 0, 6 ) . str_repeat( '•', max( 0, strlen( $val ) - 10 ) ) . substr( $val, -4 ) : '';
        ?>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <input type="password" id="extractia_api_key" name="extractia_api_key"
                   value="<?php echo esc_attr( $val ); ?>"
                   class="regular-text" autocomplete="off"
                   placeholder="<?php esc_attr_e( 'Paste your API key here', 'extractia-wp' ); ?>" />
            <button type="button" id="extractia-test-key" class="button button-secondary">
                <?php esc_html_e( 'Test connection', 'extractia-wp' ); ?>
            </button>
            <span id="extractia-key-status" style="display:none;"></span>
        </div>
        <p class="description">
            <?php printf( __( 'Current key: <code>%s</code>', 'extractia-wp' ), esc_html( $masked ?: __( 'not set', 'extractia-wp' ) ) ); ?>
        </p>
        <?php
    }

    public function field_default_template() {
        $api       = new ExtractIA_API_Client();
        $templates = $api->get_templates();
        $current   = get_option( 'extractia_default_template', '' );
        ?>
        <select name="extractia_default_template" id="extractia_default_template">
            <option value=""><?php esc_html_e( '— none (user selects) —', 'extractia-wp' ); ?></option>
            <?php if ( ! is_wp_error( $templates ) && is_array( $templates ) ) : ?>
                <?php foreach ( $templates as $tpl ) : ?>
                    <option value="<?php echo esc_attr( $tpl['id'] ); ?>"
                        <?php selected( $current, $tpl['id'] ); ?>>
                        <?php echo esc_html( $tpl['label'] ); ?>
                    </option>
                <?php endforeach; ?>
            <?php endif; ?>
        </select>
        <p class="description"><?php esc_html_e( 'Pre-select this template in the upload widget. Users can still change it unless you hide the selector via shortcode attribute.', 'extractia-wp' ); ?></p>
        <?php
    }

    public function field_workflow_mode() {
        $val = get_option( 'extractia_workflow_mode', 'inline' );
        $modes = [
            'inline'   => __( 'Show results inline (same page)', 'extractia-wp' ),
            'redirect' => __( 'Redirect to a URL after processing', 'extractia-wp' ),
            'webhook'  => __( 'Send results to a webhook URL (silent)', 'extractia-wp' ),
        ];
        foreach ( $modes as $key => $label ) : ?>
            <label style="display:block;margin-bottom:4px;">
                <input type="radio" name="extractia_workflow_mode" value="<?php echo esc_attr( $key ); ?>"
                    <?php checked( $val, $key ); ?> />
                <?php echo esc_html( $label ); ?>
            </label>
        <?php endforeach;
    }

    public function field_redirect_url() {
        $val = get_option( 'extractia_redirect_url', '' );
        echo '<input type="url" name="extractia_redirect_url" value="' . esc_attr( $val ) . '" class="regular-text" placeholder="https://example.com/thank-you" />';
        echo '<p class="description">' . esc_html__( 'Only used when "After Processing" is set to Redirect. The extracted JSON is appended as a ?data= query parameter (base64 encoded).', 'extractia-wp' ) . '</p>';
    }

    public function field_webhook_url() {
        $val = get_option( 'extractia_webhook_url', '' );
        echo '<input type="url" name="extractia_webhook_url" value="' . esc_attr( $val ) . '" class="regular-text" placeholder="https://example.com/webhook" />';
        echo '<p class="description">' . esc_html__( 'ExtractIA will POST the extracted JSON to this URL after each document is processed. Leave blank to disable.', 'extractia-wp' ) . '</p>';
    }

    private function checkbox_field( string $option, string $desc ) {
        $val = get_option( $option, '1' );
        echo '<label><input type="checkbox" name="' . esc_attr( $option ) . '" value="1" ' . checked( $val, '1', false ) . ' /> ' . esc_html( $desc ) . '</label>';
    }

    public function field_show_summary()    { $this->checkbox_field( 'extractia_show_summary',    __( 'Show "Generate AI summary" button on results', 'extractia-wp' ) ); }
    public function field_allow_multipage() { $this->checkbox_field( 'extractia_allow_multipage', __( 'Allow users to add multiple pages (multipage document)', 'extractia-wp' ) ); }
    public function field_show_usage_bar()  { $this->checkbox_field( 'extractia_show_usage_bar',  __( 'Display a usage bar at the bottom of the widget', 'extractia-wp' ) ); }

    public function field_max_file_size() {
        $val = (int) get_option( 'extractia_max_file_size_mb', 5 );
        echo '<input type="number" name="extractia_max_file_size_mb" value="' . esc_attr( $val ) . '" min="1" max="20" style="width:80px;" /> MB';
        echo '<p class="description">' . esc_html__( 'Client-side validation limit. The ExtractIA API hard-limits images to 5 MB.', 'extractia-wp' ) . '</p>';
    }

    public function field_result_fields() {
        $val = get_option( 'extractia_result_fields', '' );
        echo '<input type="text" name="extractia_result_fields" value="' . esc_attr( $val ) . '" class="regular-text" placeholder="Invoice Number, Total Amount, Date" />';
        echo '<p class="description">' . esc_html__( 'Comma-separated list of field labels to display. Leave blank to show all fields.', 'extractia-wp' ) . '</p>';
    }

    public function field_custom_css() {
        $val = get_option( 'extractia_custom_css_class', '' );
        echo '<input type="text" name="extractia_custom_css_class" value="' . esc_attr( $val ) . '" class="regular-text" placeholder="my-extractia-widget" />';
        echo '<p class="description">' . esc_html__( 'Extra CSS class added to the widget wrapper element.', 'extractia-wp' ) . '</p>';
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap extractia-admin">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <div id="extractia-settings-tabs" style="display:flex;gap:16px;align-items:flex-start;margin-top:20px;">
                <div style="flex:1;min-width:0;">
                    <form method="post" action="options.php">
                        <?php
                        settings_fields( self::OPTION_GROUP );
                        do_settings_sections( self::MENU_SLUG );
                        submit_button( __( 'Save Settings', 'extractia-wp' ) );
                        ?>
                    </form>
                </div>
                <div class="extractia-sidebar" style="width:280px;flex-shrink:0;">
                    <?php $this->render_sidebar(); ?>
                </div>
            </div>
        </div>
        <script>
        (function(){
            var btn = document.getElementById('extractia-test-key');
            if (!btn) return;
            btn.addEventListener('click', function() {
                var key = document.getElementById('extractia_api_key').value.trim();
                var status = document.getElementById('extractia-key-status');
                if (!key) { status.textContent = '<?php echo esc_js( __( 'Please enter a key.', 'extractia-wp' ) ); ?>'; status.style.display='inline'; return; }
                btn.disabled = true;
                btn.textContent = '<?php echo esc_js( __( 'Testing…', 'extractia-wp' ) ); ?>';
                fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=extractia_test_key&nonce=<?php echo esc_js( wp_create_nonce( 'extractia_test_key' ) ); ?>&key=' + encodeURIComponent(key)
                })
                .then(r => r.json())
                .then(d => {
                    status.style.display = 'inline';
                    if (d.success) {
                        status.style.color = '#00a32a';
                        status.textContent = '✓ ' + d.data.email + ' — ' + d.data.plan;
                    } else {
                        status.style.color = '#d63638';
                        status.textContent = '✗ ' + (d.data || '<?php echo esc_js( __( 'Invalid key', 'extractia-wp' ) ); ?>');
                    }
                })
                .catch(() => { status.style.color='#d63638'; status.textContent='<?php echo esc_js( __( 'Request failed', 'extractia-wp' ) ); ?>'; })
                .finally(() => { btn.disabled=false; btn.textContent='<?php echo esc_js( __( 'Test connection', 'extractia-wp' ) ); ?>'; });
            });

            // AJAX handler binding
            <?php add_action( 'wp_ajax_extractia_test_key', [ $this, 'ajax_test_key' ] ); ?>
        })();
        </script>
        <?php
    }

    // ── AJAX: test key ────────────────────────────────────────────────────────

    public function ajax_test_key() {
        check_ajax_referer( 'extractia_test_key', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'extractia-wp' ) );
        }

        $key = sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) );

        if ( empty( $key ) ) {
            wp_send_json_error( __( 'Empty key.', 'extractia-wp' ) );
        }

        $api     = new ExtractIA_API_Client( $key );
        $profile = $api->get_profile();

        if ( is_wp_error( $profile ) ) {
            wp_send_json_error( $profile->get_error_message() );
        }

        wp_send_json_success( [
            'email' => $profile['email'] ?? '',
            'plan'  => $profile['tier']  ?? $profile['plan'] ?? 'Unknown plan',
        ] );
    }

    // ── Admin notice: missing key ─────────────────────────────────────────────

    public function api_key_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $screen = get_current_screen();

        if ( ! $screen || strpos( $screen->id, 'extractia' ) === false ) {
            return;
        }

        if ( get_option( 'extractia_api_key', '' ) === '' ) {
            echo '<div class="notice notice-warning is-dismissible"><p>'
                . sprintf(
                    /* translators: %s = settings page link */
                    __( '<strong>ExtractIA:</strong> No API key configured. <a href="%s">Add your API key →</a>', 'extractia-wp' ),
                    esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) )
                )
                . '</p></div>';
        }
    }

    // ── Sidebar ───────────────────────────────────────────────────────────────

    private function render_sidebar() {
        $api     = new ExtractIA_API_Client();
        $profile = $api->get_profile();
        ?>
        <div class="extractia-card" style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px;">
            <h3 style="margin-top:0;"><?php esc_html_e( 'Account', 'extractia-wp' ); ?></h3>
            <?php if ( is_wp_error( $profile ) ) : ?>
                <p style="color:#d63638;"><?php esc_html_e( 'Could not load account info. Check your API key.', 'extractia-wp' ); ?></p>
            <?php else : ?>
                <p><strong><?php esc_html_e( 'Plan:', 'extractia-wp' ); ?></strong>
                    <?php echo esc_html( $profile['tier'] ?? $profile['plan'] ?? '—' ); ?></p>
                <?php $used  = $profile['documentsUsed']  ?? 0;
                      $limit = $profile['documentsLimit'] ?? 0; ?>
                <p><strong><?php esc_html_e( 'Documents used:', 'extractia-wp' ); ?></strong>
                    <?php echo esc_html( $used . ' / ' . ( $limit > 0 ? $limit : '∞' ) ); ?></p>
                <?php if ( $limit > 0 ) :
                    $pct = min( 100, (int) round( $used / $limit * 100 ) );
                    $color = $pct >= 90 ? '#d63638' : ( $pct >= 70 ? '#dba617' : '#00a32a' );
                    ?>
                    <div style="background:#e0e0e0;border-radius:4px;height:8px;margin:8px 0;">
                        <div style="background:<?php echo esc_attr( $color ); ?>;width:<?php echo esc_attr( $pct ); ?>%;height:8px;border-radius:4px;transition:width .3s;"></div>
                    </div>
                    <p style="font-size:11px;color:#666;margin:0;"><?php echo esc_html( $pct . '% ' . __( 'used', 'extractia-wp' ) ); ?></p>
                <?php endif; ?>
            <?php endif; ?>
            <hr />
            <h3><?php esc_html_e( 'Shortcodes', 'extractia-wp' ); ?></h3>
            <code style="display:block;background:#f6f6f6;padding:6px 8px;border-radius:4px;margin-bottom:6px;font-size:12px;">[extractia_upload]</code>
            <code style="display:block;background:#f6f6f6;padding:6px 8px;border-radius:4px;margin-bottom:6px;font-size:12px;">[extractia_docs limit="10"]</code>
            <code style="display:block;background:#f6f6f6;padding:6px 8px;border-radius:4px;margin-bottom:6px;font-size:12px;">[extractia_tool id="ocr_id"]</code>
            <code style="display:block;background:#f6f6f6;padding:6px 8px;border-radius:4px;margin-bottom:6px;font-size:12px;">[extractia_usage]</code>
            <a href="https://extractia.info/docs" target="_blank" rel="noopener" style="font-size:13px;">
                <?php esc_html_e( 'Full API Documentation →', 'extractia-wp' ); ?>
            </a>
        </div>
        <?php
    }
}
