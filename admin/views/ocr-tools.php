<?php
/**
 * ExtractIA Admin — OCR Tools Panel
 * Variables: $tools (array|WP_Error)
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="wrap extractia-admin">
    <h1><?php esc_html_e( 'OCR Tools', 'extractia-wp' ); ?></h1>
    <p><?php esc_html_e( 'Run your saved OCR tool configurations directly against an image. Each run consumes 1 document credit plus AI credits.', 'extractia-wp' ); ?></p>

    <?php if ( is_wp_error( $tools ) ) : ?>
        <div class="notice notice-error"><p><?php echo esc_html( $tools->get_error_message() ); ?></p></div>
    <?php elseif ( empty( $tools ) ) : ?>
        <p><?php printf(
            __( 'No OCR tools found. <a href="%s" target="_blank" rel="noopener">Create one on extractia.info →</a>', 'extractia-wp' ),
            'https://extractia.info/ocr-tools'
        ); ?></p>
    <?php else : ?>

    <div class="extractia-ocr-admin-grid">
        <?php foreach ( (array) $tools as $tool ) :
            $out_type = $tool['outputType'] ?? 'TEXT';
            $params   = $tool['parameterDefinitions'] ?? [];
        ?>
        <div class="extractia-card extractia-ocr-card"
             data-tool-id="<?php echo esc_attr( $tool['id'] ); ?>"
             data-output-type="<?php echo esc_attr( $out_type ); ?>">
            <h3 class="extractia-card__title">
                <?php echo esc_html( $tool['name'] ); ?>
                <small class="extractia-badge"><?php echo esc_html( $out_type ); ?></small>
            </h3>
            <p class="extractia-ocr-card__prompt"><?php echo esc_html( mb_substr( $tool['prompt'] ?? '', 0, 120 ) . ( strlen( $tool['prompt'] ?? '' ) > 120 ? '…' : '' ) ); ?></p>

            <!-- Image picker -->
            <div class="extractia-dropzone extractia-dropzone--sm extractia-admin-drop" tabindex="0" role="button">
                <p><?php esc_html_e( 'Drop image or click to browse', 'extractia-wp' ); ?></p>
                <input type="file" class="extractia-file-input" accept="image/jpeg,image/png,image/webp" style="display:none;" />
            </div>
            <div class="extractia-admin-preview-wrap"></div>

            <!-- Dynamic params -->
            <?php foreach ( $params as $p ) : ?>
            <label class="extractia-label" style="margin-top:10px;display:block;">
                <?php echo esc_html( $p['label'] ?? 'Param ' . $p['key'] ); ?>
            </label>
            <input type="text"
                   class="regular-text extractia-ocr-param__input"
                   data-param-key="<?php echo esc_attr( $p['key'] ); ?>"
                   maxlength="<?php echo esc_attr( $p['maxChars'] ?? 200 ); ?>"
                   placeholder="<?php echo esc_attr( $p['description'] ?? '' ); ?>" />
            <?php endforeach; ?>

            <button type="button" class="button button-primary extractia-admin-ocr-run" style="margin-top:12px;" disabled>
                <?php esc_html_e( 'Analyze', 'extractia-wp' ); ?>
            </button>

            <div class="extractia-ocr-admin-result" style="display:none;margin-top:12px;">
                <strong><?php esc_html_e( 'Result:', 'extractia-wp' ); ?></strong>
                <span class="extractia-ocr-admin-result__answer"></span>
                <p class="extractia-ocr-admin-result__explanation" style="color:#555;font-size:0.93em;"></p>
            </div>

            <div class="extractia-admin-spinner notice-spinner" style="display:none;float:none;"></div>
            <div class="extractia-admin-error" style="display:none;color:#d63638;margin-top:8px;"></div>

            <p class="description" style="margin-top:8px;">
                <?php printf( __( 'Shortcode: <code>[extractia_tool id="%s"]</code>', 'extractia-wp' ), esc_attr( $tool['id'] ) ); ?>
            </p>
        </div>
        <?php endforeach; ?>
    </div><!-- .extractia-ocr-admin-grid -->

    <?php endif; ?>
</div>
