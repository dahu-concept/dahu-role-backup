<?php
/**
 * Admin screen.
 *
 * @package DahuRoleBackup
 */

defined( 'ABSPATH' ) || exit;

/* =========================================================
 * ADMIN SCREEN — ROLE SNAPSHOTS
 * ========================================================= */

/**
 * Register the screen under Tools.
 *
 * In the plugin this was extracted from, these snapshots were one tab of a
 * wider settings page. Standing alone they belong under Tools: this is
 * maintenance, consulted when something has gone wrong, not a setting.
 *
 * @return void
 */
function dahu_role_backup_register_menu() {
	add_management_page(
		__( 'Role snapshots', 'dahu-role-backup' ),
		__( 'Role snapshots', 'dahu-role-backup' ),
		'manage_options',
		DAHU_ROLE_BACKUP_SLUG,
		'dahu_role_backup_render_page'
	);
}
add_action( 'admin_menu', 'dahu_role_backup_register_menu' );

add_action( 'admin_enqueue_scripts', 'dahu_role_backup_enqueue_admin_style' );
/**
 * Loads this screen's stylesheet, on this screen only.
 *
 * @param string $hook Current admin page hook suffix.
 * @return void
 */
function dahu_role_backup_enqueue_admin_style( $hook ) {

	if ( 'tools_page_' . DAHU_ROLE_BACKUP_SLUG !== $hook ) {
		return;
	}

	wp_enqueue_style(
		'dahu-role-backup-admin',
		DAHU_ROLE_BACKUP_URL . 'assets/css/admin.css',
		array(),
		DAHU_ROLE_BACKUP_VERSION
	);
}

/* ---------------------------------------------------------------------------
 * Action handling (manual snapshot, restore).
 *
 * Done on admin_post rather than while rendering the page: a restore has side
 * effects, so it must never start from a mere page view, nor be replayable by
 * refreshing the browser.
 * ------------------------------------------------------------------------ */

add_action( 'admin_post_dahu_role_backup_snapshot', 'dahu_role_backup_handle_snapshot' );

/**
 * Handles the "take a snapshot now" button.
 *
 * @return void
 */
function dahu_role_backup_handle_snapshot() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You are not allowed to access this page.', 'dahu-role-backup' ) );
    }

    check_admin_referer( 'dahu_role_backup_snapshot' );

    $result = dahu_role_backup_write( 'manual', dahu_role_backup_read_current(), array( 'evenement' => 'manuel' ) );

    if ( is_wp_error( $result ) ) {
        $args = array( 'dahu_msg' => 'erreur', 'dahu_detail' => rawurlencode( $result->get_error_message() ) );
    } elseif ( ! empty( $result['skipped'] ) ) {
        $args = array( 'dahu_msg' => 'identique' );
    } else {
        $args = array( 'dahu_msg' => 'instantane', 'dahu_detail' => rawurlencode( $result['file'] ) );
    }

    wp_safe_redirect( add_query_arg( $args, dahu_role_backup_url() ) );
    exit;
}

add_action( 'admin_post_dahu_role_backup_restore', 'dahu_role_backup_handle_restore' );

/**
 * Handles both restore forms.
 *
 * @return void
 */
function dahu_role_backup_handle_restore() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You are not allowed to access this page.', 'dahu-role-backup' ) );
    }

    $file = isset( $_POST['fichier'] ) ? basename( wp_unslash( $_POST['fichier'] ) ) : '';

    check_admin_referer( 'dahu_role_backup_restore_' . $file );

    $full   = ! empty( $_POST['full'] );
    $result = dahu_role_backup_restore( $file, $full );

    if ( is_wp_error( $result ) ) {
        $args = array( 'dahu_msg' => 'erreur', 'dahu_detail' => rawurlencode( $result->get_error_message() ) );
    } else {
        $args = array(
            'dahu_msg'    => 'restaure',
            'dahu_detail' => rawurlencode( empty( $result['restaures'] ) ? __( '(no role to restore)', 'dahu-role-backup' ) : implode( ', ', $result['restaures'] ) ),
            'dahu_mode'   => $result['mode'],
        );
    }

    wp_safe_redirect( add_query_arg( $args, dahu_role_backup_url() ) );
    exit;
}

/**
 * The site's own date-and-time format, for a value shown on screen.
 *
 * Centralised so no screen formats a date by hand. A hard-coded pattern such
 * as d/m/Y is wrong for most of the world: 03/10/2026 reads as March to an
 * American visitor and as October to a European one, and on a screen whose
 * whole subject is which snapshot was taken when, that is a contradiction
 * rather than a cosmetic detail. The site's own settings decide instead.
 *
 * Machine-side timestamps are deliberately NOT routed through this: snapshot
 * file names, the snapshot JSON and the log keep their fixed UTC patterns,
 * which have to stay sortable and stable.
 *
 * @return string Format string for wp_date().
 */
function dahu_role_backup_datetime_format() {
    return get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
}

/**
 * Feedback banner shown after an action.
 *
 * @return void
 */
function dahu_role_backup_render_notices() {
    if ( empty( $_GET['dahu_msg'] ) ) {
        return;
    }

    $msg    = sanitize_key( wp_unslash( $_GET['dahu_msg'] ) );
    $detail = isset( $_GET['dahu_detail'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['dahu_detail'] ) ) ) : '';
    $mode   = isset( $_GET['dahu_mode'] ) ? sanitize_key( wp_unslash( $_GET['dahu_mode'] ) ) : '';

    switch ( $msg ) {
        case 'instantane':
            printf(
                '<div class="notice notice-success is-dismissible"><p>%1$s <code>%2$s</code></p></div>',
                esc_html__( 'Snapshot created:', 'dahu-role-backup' ),
                esc_html( $detail )
            );
            break;

        case 'identique':
            printf(
                '<div class="notice notice-info is-dismissible"><p>%s</p></div>',
                esc_html__( 'No snapshot created: the roles are identical to the last one recorded.', 'dahu-role-backup' )
            );
            break;

        case 'restaure':
            // Two whole sentences rather than one sentence with the mode
            // interpolated: an adjective agrees differently in every language,
            // and a translator cannot fix that from a bare "full"/"additive".
            printf(
                '<div class="notice notice-success is-dismissible"><p>%1$s <strong>%2$s</strong></p></div>',
                'full' === $mode
                    ? esc_html__( 'Full restore done. Roles restored:', 'dahu-role-backup' )
                    : esc_html__( 'Additive restore done. Roles restored:', 'dahu-role-backup' ),
                esc_html( $detail )
            );
            break;

        case 'erreur':
            printf(
                '<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
                esc_html__( 'Failure:', 'dahu-role-backup' ),
                esc_html( $detail )
            );
            break;
    }
}

/**
 * Renders the page: the list of snapshots, or the preview of the difference
 * when one snapshot is selected.
 *
 * @return void
 */
function dahu_role_backup_render_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You are not allowed to access this page.', 'dahu-role-backup' ) );
    }

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__( 'Role snapshots', 'dahu-role-backup' ) . '</h1>';

    printf(
        '<p class="dahu-role-backup__intro">%s</p>',
        esc_html__( 'Dahu Role Backup keeps a copy of every WordPress role, taken daily and on every change, so a capability lost by mistake can be compared and put back.', 'dahu-role-backup' )
    );

    echo '<hr class="wp-header-end">';

    dahu_role_backup_render_notices();

    $preview = isset( $_GET['apercu'] ) ? basename( wp_unslash( $_GET['apercu'] ) ) : '';

    if ( $preview ) {
        dahu_role_backup_render_preview( $preview );
    } else {
        dahu_role_backup_render_overview();
    }

    echo '</div>';
}

/**
 * Main view: health status plus the table of snapshots.
 *
 * @return void
 */
function dahu_role_backup_render_overview() {
    $all     = dahu_role_backup_list();
    $current = dahu_role_backup_read_current();
    $age     = dahu_role_backup_last_age();
    $best    = dahu_role_backup_best_recent( $all );
    $next    = wp_next_scheduled( DAHU_ROLE_BACKUP_CRON );

    echo '<h2>' . esc_html__( 'Status', 'dahu-role-backup' ) . '</h2>';
    echo '<table class="widefat dahu-rb-table"><tbody>';

    printf(
        '<tr><th class="dahu-rb-col-label">%1$s</th><td><strong>%2$d</strong> — %3$s</td></tr>',
        esc_html__( 'Roles currently in the database', 'dahu-role-backup' ),
        count( $current ),
        esc_html( implode( ', ', dahu_role_backup_slugs( $current ) ) )
    );

    printf(
        '<tr><th>%1$s</th><td>%2$s</td></tr>',
        esc_html__( 'Last snapshot', 'dahu-role-backup' ),
        null === $age
            ? '<span class="dahu-rb-warning">' . esc_html__( 'none', 'dahu-role-backup' ) . '</span>'
            : esc_html(
                sprintf(
                    /* translators: 1: human-readable age, for example "3 days"; 2: snapshot file name. */
                    __( '%1$s ago (%2$s)', 'dahu-role-backup' ),
                    human_time_diff( time() - $age ),
                    $all[0]['file']
                )
            )
    );

    printf(
        '<tr><th>%1$s</th><td>%2$s</td></tr>',
        esc_html__( 'Next daily snapshot', 'dahu-role-backup' ),
        $next
            ? esc_html( wp_date( dahu_role_backup_datetime_format(), $next ) )
            : '<span class="dahu-rb-warning">' . esc_html__( 'not scheduled', 'dahu-role-backup' ) . '</span>'
    );

    printf(
        '<tr><th>%1$s</th><td>%2$s</td></tr>',
        esc_html__( 'Snapshots kept', 'dahu-role-backup' ),
        esc_html(
            sprintf(
                /* translators: 1: number of snapshots on disk; 2: daily retention limit; 3: on-change retention limit. */
                __( '%1$d (limit: %2$d daily + %3$d on change)', 'dahu-role-backup' ),
                count( $all ),
                DAHU_ROLE_BACKUP_KEEP_DAILY,
                DAHU_ROLE_BACKUP_KEEP_CHANGE
            )
        )
    );

    printf(
        '<tr><th>%1$s</th><td>%2$s</td></tr>',
        esc_html__( 'Snapshot protected from pruning', 'dahu-role-backup' ),
        $best
            ? esc_html(
                sprintf(
                    /* translators: 1: snapshot file name; 2: number of roles it holds. */
                    _n( '%1$s (%2$d role)', '%1$s (%2$d roles)', $best['count'], 'dahu-role-backup' ),
                    $best['file'],
                    $best['count']
                )
            )
            : '—'
    );

    printf(
        '<tr><th>%1$s</th><td><code>%2$s</code></td></tr>',
        esc_html__( 'Directory', 'dahu-role-backup' ),
        esc_html( dahu_role_backup_dir() )
    );

    echo '</tbody></table>';

    printf(
        '<form method="post" action="%1$s" class="dahu-rb-form">%2$s<input type="hidden" name="action" value="dahu_role_backup_snapshot"><button type="submit" class="button button-primary">%3$s</button></form>',
        esc_url( admin_url( 'admin-post.php' ) ),
        wp_nonce_field( 'dahu_role_backup_snapshot', '_wpnonce', true, false ),
        esc_html__( 'Take a snapshot now', 'dahu-role-backup' )
    );

    echo '<h2>' . esc_html__( 'Snapshots', 'dahu-role-backup' ) . '</h2>';

    if ( empty( $all ) ) {
        echo '<p>' . esc_html__( 'No snapshot yet.', 'dahu-role-backup' ) . '</p>';
        return;
    }

    /*
     * The list is capped by default. It grows to the retention limit — thirty
     * daily plus thirty on change — and sixty rows of near-identical filenames
     * is a wall, not a list: the snapshot somebody wants is almost always one
     * of the most recent few. The rest are one link away, and the line below
     * says how many there are rather than hiding the fact.
     */
    $show_all = isset( $_GET['all'] ) && '1' === $_GET['all']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
    $total    = count( $all );
    $rows     = $show_all ? $all : array_slice( $all, 0, DAHU_ROLE_BACKUP_LIST_LIMIT );

    echo '<table class="widefat striped dahu-rb-snapshots"><thead><tr>';
    printf(
        '<th>%1$s</th><th>%2$s</th><th class="dahu-rb-snapshots__num">%3$s</th><th>%4$s</th><th></th>',
        esc_html__( 'Date', 'dahu-role-backup' ),
        esc_html__( 'Kind', 'dahu-role-backup' ),
        esc_html__( 'Roles', 'dahu-role-backup' ),
        esc_html__( 'File', 'dahu-role-backup' )
    );
    echo '</tr></thead><tbody>';

    $labels = array(
        'daily'  => __( 'Daily', 'dahu-role-backup' ),
        'change' => __( 'Change', 'dahu-role-backup' ),
        'manual' => __( 'Manual', 'dahu-role-backup' ),
    );

    $any_protected = false;

    foreach ( $rows as $meta ) {
        $url = add_query_arg(
            array( 'apercu' => $meta['file'] ),
            dahu_role_backup_url()
        );

        $is_best = ( $best && $best['file'] === $meta['file'] );

        if ( $is_best ) {
            $any_protected = true;
        }

        /*
         * A link, not a button. Thirty-five outlined buttons stacked do not
         * rank anything — they make a column of identical blocks, and the
         * button was the widest element in its row, so it set the column
         * width too. An outlined button is for the action of the SCREEN
         * ("Take a snapshot now"); a row action is a link, which is what
         * WordPress's own list tables do.
         *
         * The size rides along with the file name instead of holding a column
         * of its own: every row read "12 KB" or "13 KB", which is a column
         * that answers a question nobody asked.
         */
        printf(
            '<tr><td>%1$s</td><td>%2$s</td><td class="dahu-rb-snapshots__num"><strong>%3$d</strong>%4$s</td><td><code>%5$s</code> <span class="dahu-rb-snapshots__size">%6$s</span></td><td class="dahu-rb-snapshots__action"><a href="%7$s">%8$s</a></td></tr>',
            esc_html( wp_date( dahu_role_backup_datetime_format(), $meta['timestamp'] ) ),
            esc_html( isset( $labels[ $meta['type'] ] ) ? $labels[ $meta['type'] ] : $meta['type'] ),
            $meta['count'],
            $is_best
                ? ' <span class="dahu-rb-protected">&#9733;</span>'
                : '',
            esc_html( $meta['file'] ),
            esc_html( size_format( (int) $meta['size'] ) ),
            esc_url( $url ),
            esc_html__( 'Compare / restore', 'dahu-role-backup' )
        );
    }

    echo '</tbody></table>';

    // A glyph in a table has a key, next to the table. This one had its
    // explanation in a panel further up the screen, which is no explanation at
    // all for somebody reading the row.
    if ( $any_protected ) {
        printf(
            '<p class="dahu-rb-legend"><span class="dahu-rb-protected">&#9733;</span> %s</p>',
            esc_html__( 'Kept whatever the retention limit says: it is the most complete snapshot, and the one a restore should usually start from.', 'dahu-role-backup' )
        );
    }

    if ( ! $show_all && $total > count( $rows ) ) {
        printf(
            '<p class="dahu-rb-legend">%1$s <a href="%2$s">%3$s</a></p>',
            esc_html(
                sprintf(
                    /* translators: %s: how many older snapshots are not shown. */
                    _n( '%s older snapshot is not shown.', '%s older snapshots are not shown.', $total - count( $rows ), 'dahu-role-backup' ),
                    number_format_i18n( $total - count( $rows ) )
                )
            ),
            esc_url( add_query_arg( 'all', '1', dahu_role_backup_url() ) ),
            esc_html__( 'Show every snapshot', 'dahu-role-backup' )
        );
    }
}

/**
 * Preview of the difference, shown before a restore.
 *
 * A "restore" button is never offered without first showing what the restore
 * would change: that is the only way for an administrator to check they picked
 * the right snapshot.
 *
 * @param string $filename Bare snapshot file name, no path.
 * @return void
 */
function dahu_role_backup_render_preview( $filename ) {
    $back = dahu_role_backup_url();

    $snapshot = dahu_role_backup_load( $filename );

    if ( is_wp_error( $snapshot ) ) {
        printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $snapshot->get_error_message() ) );
        printf(
            '<p><a href="%1$s">&larr; %2$s</a></p>',
            esc_url( $back ),
            esc_html__( 'Back', 'dahu-role-backup' )
        );
        return;
    }

    $origin = dahu_role_backup_check_origin( $snapshot );
    $diff   = dahu_role_backup_diff( $snapshot );

    printf(
        '<p><a href="%1$s">&larr; %2$s</a></p>',
        esc_url( $back ),
        esc_html__( 'Back to the list', 'dahu-role-backup' )
    );
    printf(
        '<h2>%1$s <code>%2$s</code></h2>',
        esc_html__( 'Snapshot', 'dahu-role-backup' ),
        esc_html( $snapshot['file'] )
    );

    // The snapshot stores date_utc as ISO 8601, which is right for a file and
    // unreadable on a screen. It is reformatted here for display only; the
    // stored value is never rewritten. An unparseable or absent value keeps the
    // existing '?' fallback rather than showing a wrong date.
    $created = isset( $snapshot['date_utc'] ) ? strtotime( (string) $snapshot['date_utc'] ) : false;

    printf(
        '<p>%1$s — %2$s <code>%3$s</code></p>',
        esc_html(
            sprintf(
                /* translators: 1: date the snapshot was created; 2: number of roles held by the snapshot. */
                _n( 'Created on %1$s — %2$d role', 'Created on %1$s — %2$d roles', count( $snapshot['roles'] ), 'dahu-role-backup' ),
                $created ? wp_date( dahu_role_backup_datetime_format(), $created ) : '?',
                count( $snapshot['roles'] )
            )
        ),
        esc_html__( 'origin:', 'dahu-role-backup' ),
        esc_html( isset( $snapshot['table_prefix'] ) ? $snapshot['table_prefix'] : '?' )
    );

    if ( ! empty( $snapshot['roles_perdus'] ) ) {
        printf(
            '<div class="notice notice-warning inline"><p>%1$s <strong>%2$s</strong></p></div>',
            esc_html__( 'This snapshot was triggered by the disappearance of:', 'dahu-role-backup' ),
            esc_html( implode( ', ', (array) $snapshot['roles_perdus'] ) )
        );
    }

    if ( is_wp_error( $origin ) ) {
        printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $origin->get_error_message() ) );
        return;
    }

    echo '<h3>' . esc_html__( 'What the restore would change', 'dahu-role-backup' ) . '</h3>';
    echo '<table class="widefat dahu-rb-table"><tbody>';

    printf(
        '<tr><th class="dahu-rb-col-label--wide">%1$s</th><td>%2$s</td></tr>',
        esc_html__( 'Roles to recreate (additive mode)', 'dahu-role-backup' ),
        empty( $diff['manquants'] )
            ? '<em>' . esc_html__( 'none — nothing to restore', 'dahu-role-backup' ) . '</em>'
            : '<strong>' . esc_html( implode( ', ', $diff['manquants'] ) ) . '</strong>'
    );

    printf(
        '<tr><th>%1$s</th><td>%2$s</td></tr>',
        esc_html__( 'Roles missing from this snapshot', 'dahu-role-backup' ),
        empty( $diff['nouveaux'] )
            ? '<em>' . esc_html__( 'none', 'dahu-role-backup' ) . '</em>'
            : esc_html( implode( ', ', $diff['nouveaux'] ) ) . ' <span class="dahu-rb-warning">' . esc_html__( '(kept in additive mode, DELETED in full mode)', 'dahu-role-backup' ) . '</span>'
    );

    printf(
        '<tr><th>%1$s</th><td>%2$s</td></tr>',
        esc_html__( 'Roles already present', 'dahu-role-backup' ),
        esc_html( implode( ', ', $diff['communs'] ) )
    );

    echo '</tbody></table>';

    $nonce  = wp_nonce_field( 'dahu_role_backup_restore_' . $snapshot['file'], '_wpnonce', true, false );
    $action = esc_url( admin_url( 'admin-post.php' ) );
    $file   = esc_attr( $snapshot['file'] );

    // TWO separate forms, rather than a single one carrying a checkbox: with
    // one form, ticking "full replacement" and then clicking the additive
    // button would still send full=1. The most destructive mode must never be
    // reachable through the most innocuous button.
    printf(
        '<form method="post" action="%1$s" class="dahu-rb-form--spaced">%2$s
        <input type="hidden" name="action" value="dahu_role_backup_restore">
        <input type="hidden" name="fichier" value="%3$s">
        <p><button type="submit" class="button button-primary"%4$s>%5$s</button></p>
        </form>',
        $action,
        $nonce,
        $file,
        empty( $diff['manquants'] ) ? ' disabled' : '',
        esc_html__( 'Restore the missing roles (additive)', 'dahu-role-backup' )
    );

    $lost = empty( $diff['nouveaux'] ) ? '' : ': ' . implode( ', ', $diff['nouveaux'] );

    // esc_js(), not esc_html(): this string is injected into a JavaScript
    // string literal inside an HTML attribute. A translation containing an
    // apostrophe — which French, Italian and many others will — would close
    // the literal early and break the button without it.
    $confirm = esc_js( __( 'Full replacement of the roles. This action cannot be undone without a restore. Confirm?', 'dahu-role-backup' ) );

    printf(
        '<form method="post" action="%1$s" class="dahu-rb-form--danger">%2$s
        <input type="hidden" name="action" value="dahu_role_backup_restore">
        <input type="hidden" name="fichier" value="%3$s">
        <input type="hidden" name="full" value="1">
        <h3 class="dahu-rb-heading-flush">%4$s</h3>
        <p>%5$s <strong class="dahu-rb-warning">%6$s</strong></p>
        <p><label><input type="checkbox" onclick="document.getElementById(\'dahu-full\').disabled = !this.checked;"> %7$s</label></p>
        <p><button type="submit" id="dahu-full" class="button" disabled onclick="return confirm(\'%8$s\');">%9$s</button></p>
        </form>',
        $action,
        $nonce,
        $file,
        esc_html__( 'Full replacement', 'dahu-role-backup' ),
        esc_html__( 'Overwrites the whole set of roles with this snapshot.', 'dahu-role-backup' ),
        esc_html(
            sprintf(
                /* translators: %s: empty, or a colon followed by the comma-separated roles that would be deleted. */
                __( 'Every role created since will be permanently deleted%s.', 'dahu-role-backup' ),
                $lost
            )
        ),
        esc_html__( 'I understand and I confirm.', 'dahu-role-backup' ),
        $confirm,
        esc_html__( 'Replace in full', 'dahu-role-backup' )
    );
}
