<?php
/**
 * ExtractIA Admin — Sub-Users Panel
 * Variables: $subusers (array|WP_Error)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$all_perms = [ 'upload', 'view', 'template', 'settings', 'export', 'ocr_tools', 'gallery', 'smart_scan', 'ia_agent' ];
?>
<div class="wrap extractia-admin">
    <h1><?php esc_html_e( 'Sub-Users', 'extractia-wp' ); ?></h1>
    <p><?php esc_html_e( 'Sub-users can log in to the ExtractIA web app and operate within the permissions you grant. Manage them here or on extractia.info.', 'extractia-wp' ); ?></p>

    <p>
        <a href="https://extractia.info/dashboard" target="_blank" rel="noopener" class="button button-secondary">
            <?php esc_html_e( 'Manage on extractia.info ↗', 'extractia-wp' ); ?>
        </a>
    </p>

    <?php if ( is_wp_error( $subusers ) ) : ?>
        <div class="notice notice-error"><p><?php echo esc_html( $subusers->get_error_message() ); ?></p></div>
    <?php elseif ( empty( $subusers ) ) : ?>
        <div class="notice notice-info"><p><?php esc_html_e( 'No sub-users on this account yet.', 'extractia-wp' ); ?></p></div>
    <?php else : ?>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width:180px;"><?php esc_html_e( 'Username', 'extractia-wp' ); ?></th>
                <th><?php esc_html_e( 'Permissions', 'extractia-wp' ); ?></th>
                <th style="width:140px;"><?php esc_html_e( 'Allowed Forms', 'extractia-wp' ); ?></th>
                <th style="width:90px;"><?php esc_html_e( 'Status', 'extractia-wp' ); ?></th>
                <th style="width:190px;"><?php esc_html_e( 'Last seen', 'extractia-wp' ); ?></th>
                <th style="width:180px;"><?php esc_html_e( 'Actions', 'extractia-wp' ); ?></th>
            </tr>
        </thead>
        <tbody id="extractia-subusers-table">
        <?php foreach ( (array) $subusers as $su ) :
            $suspended    = ! empty( $su['suspended'] );
            $username     = $su['username']     ?? '';
            $perms        = $su['permissions']  ?? [];
            $allowed_ids  = $su['allowedFormIds'] ?? [];
            $location     = $su['lastKnownLocation'] ?? '';
            $location_at  = $su['lastLocationAt']    ?? '';
        ?>
        <tr id="extractia-su-row-<?php echo esc_attr( $username ); ?>"
            class="<?php echo $suspended ? 'extractia-row--suspended' : ''; ?>">
            <td>
                <strong><?php echo esc_html( $username ); ?></strong>
                <?php if ( $suspended ) : ?>
                    <br><span class="extractia-badge extractia-badge--suspended"><?php esc_html_e( 'Suspended', 'extractia-wp' ); ?></span>
                <?php endif; ?>
            </td>
            <td>
                <?php foreach ( $all_perms as $p ) :
                    $has = in_array( $p, (array) $perms, true );
                ?>
                <span class="extractia-perm <?php echo $has ? 'extractia-perm--on' : 'extractia-perm--off'; ?>">
                    <?php echo esc_html( $p ); ?>
                </span>
                <?php endforeach; ?>
            </td>
            <td>
                <?php if ( empty( $allowed_ids ) ) : ?>
                    <em style="color:#999;"><?php esc_html_e( 'All forms', 'extractia-wp' ); ?></em>
                <?php else : ?>
                    <small><?php echo esc_html( implode( ', ', (array) $allowed_ids ) ); ?></small>
                <?php endif; ?>
            </td>
            <td>
                <span class="extractia-badge extractia-badge--<?php echo $suspended ? 'suspended' : 'active'; ?>">
                    <?php echo $suspended ? esc_html__( 'Suspended', 'extractia-wp' ) : esc_html__( 'Active', 'extractia-wp' ); ?>
                </span>
            </td>
            <td>
                <?php if ( $location ) : ?>
                    <small>
                        📍 <?php echo esc_html( $location ); ?><br>
                        <?php if ( $location_at ) : ?>
                            <span style="color:#999;"><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $location_at ) ) ); ?></span>
                        <?php endif; ?>
                    </small>
                <?php else : ?>
                    <span style="color:#ccc;">—</span>
                <?php endif; ?>
            </td>
            <td>
                <button type="button"
                        class="button button-small extractia-su-suspend"
                        data-username="<?php echo esc_attr( $username ); ?>"
                        data-suspended="<?php echo esc_attr( $suspended ? '1' : '0' ); ?>">
                    <?php echo $suspended ? esc_html__( 'Unsuspend', 'extractia-wp' ) : esc_html__( 'Suspend', 'extractia-wp' ); ?>
                </button>
                <button type="button"
                        class="button button-small button-link-delete extractia-su-delete"
                        style="margin-left:4px;"
                        data-username="<?php echo esc_attr( $username ); ?>">
                    <?php esc_html_e( 'Delete', 'extractia-wp' ); ?>
                </button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <p class="description" style="margin-top:12px;">
        <?php esc_html_e( 'Deleting a sub-user immediately invalidates their token — any in-flight request will receive 401 Unauthorized.', 'extractia-wp' ); ?>
    </p>

    <?php endif; ?>
</div>

<script>
(function () {
    'use strict';
    var adminCfg = window.ExtractIAAdmin || {};
    var nonce    = adminCfg.nonce   || '';
    var ajaxUrl  = adminCfg.ajaxUrl || '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';

    function post(action, username, extra) {
        var body = 'action=' + action + '&nonce=' + encodeURIComponent(nonce) + '&username=' + encodeURIComponent(username);
        if (extra) body += extra;
        return fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body,
        }).then(function(r) { return r.json(); });
    }

    // Suspend / Unsuspend
    document.querySelectorAll('.extractia-su-suspend').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var username  = btn.dataset.username;
            var suspended = btn.dataset.suspended === '1';
            btn.disabled = true;
            post('extractia_suspend_subuser', username).then(function (res) {
                if (res.success) {
                    var row    = document.getElementById('extractia-su-row-' + username);
                    var newSus = !!res.data.suspended;
                    btn.dataset.suspended = newSus ? '1' : '0';
                    btn.textContent = newSus ? '<?php echo esc_js( __( 'Unsuspend', 'extractia-wp' ) ); ?>' : '<?php echo esc_js( __( 'Suspend', 'extractia-wp' ) ); ?>';
                    if (row) row.className = newSus ? 'extractia-row--suspended' : '';
                } else {
                    alert((res.data && res.data.error) || '<?php echo esc_js( __( 'Error', 'extractia-wp' ) ); ?>');
                    btn.disabled = false;
                }
            }).catch(function () { btn.disabled = false; });
        });
    });

    // Delete
    document.querySelectorAll('.extractia-su-delete').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var username = btn.dataset.username;
            if (!confirm('<?php echo esc_js( __( 'Delete sub-user', 'extractia-wp' ) ); ?> "' + username + '"? <?php echo esc_js( __( 'Their token will be immediately revoked.', 'extractia-wp' ) ); ?>')) return;
            btn.disabled = true;
            post('extractia_delete_subuser', username).then(function (res) {
                if (res.success) {
                    var row = document.getElementById('extractia-su-row-' + username);
                    if (row) row.remove();
                } else {
                    alert((res.data && res.data.error) || '<?php echo esc_js( __( 'Error', 'extractia-wp' ) ); ?>');
                    btn.disabled = false;
                }
            }).catch(function () { btn.disabled = false; });
        });
    });
})();
</script>
