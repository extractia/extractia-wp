<?php
/**
 * ExtractIA Admin — Dashboard View
 * Variables available: $profile (array|WP_Error), $credits (array|WP_Error), $recent (array|WP_Error), $has_key (bool)
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="wrap extractia-admin">
    <h1>ExtractIA — <?php esc_html_e( 'Dashboard', 'extractia-wp' ); ?></h1>

    <?php if ( ! $has_key ) : ?>
    <div class="notice notice-warning">
        <p><?php printf(
            __( '<strong>ExtractIA:</strong> API key not set. <a href="%s">Configure it now →</a>', 'extractia-wp' ),
            esc_url( admin_url( 'admin.php?page=extractia-settings' ) )
        ); ?></p>
    </div>
    <?php endif; ?>

    <div class="extractia-dashboard-grid">

        <!-- ── Account card ─────────────────────────────── -->
        <div class="extractia-card">
            <h2 class="extractia-card__title"><?php esc_html_e( 'Account', 'extractia-wp' ); ?></h2>
            <?php if ( is_wp_error( $profile ) ) : ?>
                <p class="extractia-error"><?php echo esc_html( $profile->get_error_message() ); ?></p>
            <?php else :
                $used  = (int)( $profile['documentsUsed']  ?? 0 );
                $limit = (int)( $profile['documentsLimit'] ?? 0 );
                $pct   = $limit > 0 ? min( 100, (int) round( $used / $limit * 100 ) ) : 0;
                $color = $pct >= 90 ? '#d63638' : ( $pct >= 70 ? '#dba617' : '#00a32a' );
            ?>
                <table class="extractia-info-table">
                    <tr><th><?php esc_html_e( 'Email', 'extractia-wp' ); ?></th><td><?php echo esc_html( $profile['email'] ?? '—' ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Plan', 'extractia-wp' ); ?></th><td><?php echo esc_html( $profile['tier'] ?? $profile['plan'] ?? '—' ); ?></td></tr>
                    <tr>
                        <th><?php esc_html_e( 'Documents', 'extractia-wp' ); ?></th>
                        <td>
                            <?php echo esc_html( $used . ' / ' . ( $limit > 0 ? $limit : '∞' ) ); ?>
                            <?php if ( $limit > 0 ) : ?>
                            <div class="extractia-usage-bar" style="margin-top:6px;">
                                <div class="extractia-usage-bar__fill" style="width:<?php echo esc_attr( $pct ); ?>%;background:<?php echo esc_attr( $color ); ?>;"></div>
                            </div>
                            <small style="color:<?php echo esc_attr( $color ); ?>"><?php echo esc_html( $pct . '% ' . __( 'used', 'extractia-wp' ) ); ?></small>
                            <?php if ( $pct >= 90 ) : ?>
                            <p class="extractia-warn"><?php esc_html_e( 'You are near your document limit. Consider upgrading your plan.', 'extractia-wp' ); ?></p>
                            <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            <?php endif; ?>
        </div>

        <!-- ── AI Credits card ──────────────────────────── -->
        <div class="extractia-card">
            <h2 class="extractia-card__title"><?php esc_html_e( 'AI Credits', 'extractia-wp' ); ?></h2>
            <?php if ( is_wp_error( $credits ) ) : ?>
                <p class="extractia-error"><?php echo esc_html( $credits->get_error_message() ); ?></p>
            <?php else : ?>
                <table class="extractia-info-table">
                    <tr><th><?php esc_html_e( 'Monthly', 'extractia-wp' ); ?></th><td><?php echo esc_html( $credits['monthlyBalance'] ?? '—' ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Add-on', 'extractia-wp' ); ?></th><td><?php echo esc_html( $credits['addonBalance'] ?? '—' ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Total', 'extractia-wp' ); ?></th><td><strong><?php echo esc_html( $credits['totalBalance'] ?? '—' ); ?></strong></td></tr>
                </table>
            <?php endif; ?>
        </div>

        <!-- ── Quick links ──────────────────────────────── -->
        <div class="extractia-card">
            <h2 class="extractia-card__title"><?php esc_html_e( 'Quick Links', 'extractia-wp' ); ?></h2>
            <ul class="extractia-links">
                <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=extractia-settings' ) ); ?>"><?php esc_html_e( '⚙ Settings', 'extractia-wp' ); ?></a></li>
                <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=extractia-ocr-tools' ) ); ?>"><?php esc_html_e( '🔬 OCR Tools', 'extractia-wp' ); ?></a></li>
                <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=extractia-subusers' ) ); ?>"><?php esc_html_e( '👥 Sub-Users', 'extractia-wp' ); ?></a></li>
                <li><a href="https://extractia.info/dashboard" target="_blank" rel="noopener"><?php esc_html_e( '🌐 ExtractIA Dashboard ↗', 'extractia-wp' ); ?></a></li>
                <li><a href="https://extractia.info/docs" target="_blank" rel="noopener"><?php esc_html_e( '📖 API Docs ↗', 'extractia-wp' ); ?></a></li>
            </ul>
        </div>

        <!-- ── Recent documents ─────────────────────────── -->
        <div class="extractia-card extractia-card--full">
            <h2 class="extractia-card__title"><?php esc_html_e( 'Recent Documents', 'extractia-wp' ); ?></h2>
            <?php if ( is_wp_error( $recent ) ) : ?>
                <p class="extractia-error"><?php echo esc_html( $recent->get_error_message() ); ?></p>
            <?php elseif ( empty( $recent ) ) : ?>
                <p class="extractia-empty"><?php esc_html_e( 'No documents yet.', 'extractia-wp' ); ?></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped extractia-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'ID', 'extractia-wp' ); ?></th>
                            <th><?php esc_html_e( 'Template', 'extractia-wp' ); ?></th>
                            <th><?php esc_html_e( 'Date', 'extractia-wp' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'extractia-wp' ); ?></th>
                            <th><?php esc_html_e( 'Pages', 'extractia-wp' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( (array) $recent as $doc ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( substr( $doc['id'] ?? '', 0, 12 ) . '…' ); ?></code></td>
                            <td><?php echo esc_html( $doc['templateName'] ?? $doc['templateId'] ?? '—' ); ?></td>
                            <td><?php echo esc_html( isset( $doc['uploadedAt'] ) ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $doc['uploadedAt'] ) ) : '—' ); ?></td>
                            <td>
                                <span class="extractia-badge extractia-badge--<?php echo esc_attr( strtolower( $doc['status'] ?? 'pending' ) ); ?>">
                                    <?php echo esc_html( $doc['status'] ?? 'PENDING' ); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html( $doc['pages'] ?? 1 ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

    </div><!-- .extractia-dashboard-grid -->
</div>
