<?php
/**
 * Admin screen.
 *
 * @package UbacRoleBackup
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
function ubac_role_backup_register_menu() {
	add_management_page(
		__( 'Role snapshots', 'dahuconcept-role-backup' ),
		__( 'Role snapshots', 'dahuconcept-role-backup' ),
		'manage_options',
		UBAC_ROLE_BACKUP_SLUG,
		'ubac_role_backup_render_page'
	);
}
add_action( 'admin_menu', 'ubac_role_backup_register_menu' );

add_action( 'admin_enqueue_scripts', 'ubac_role_backup_enqueue_admin_style' );
/**
 * Loads this screen's stylesheet, on this screen only.
 *
 * @param string $hook Current admin page hook suffix.
 * @return void
 */
function ubac_role_backup_enqueue_admin_style( $hook ) {

	if ( 'tools_page_' . UBAC_ROLE_BACKUP_SLUG !== $hook ) {
		return;
	}

	// The family sheet first, and its own second: the plugin's own rules
	// are written to sit on top of the shared components, so the order is
	// a dependency and not a hope. One handle across the family -- the
	// file is byte-identical everywhere, so whichever plugin loads it
	// first serves all of them, and a shop with six of these installed
	// downloads one copy.
	wp_enqueue_style(
		'ubac-ui',
		UBAC_ROLE_BACKUP_URL . 'assets/css/ubac-ui.css',
		array(),
		UBAC_ROLE_BACKUP_VERSION
	);

	wp_enqueue_style(
		'ubac-role-backup-admin',
		UBAC_ROLE_BACKUP_URL . 'assets/css/admin.css',
		array( 'ubac-ui' ),
		UBAC_ROLE_BACKUP_VERSION
	);
}

/* ---------------------------------------------------------------------------
 * Action handling (manual snapshot, restore).
 *
 * Done on admin_post rather than while rendering the page: a restore has side
 * effects, so it must never start from a mere page view, nor be replayable by
 * refreshing the browser.
 * ------------------------------------------------------------------------ */

add_action( 'admin_post_ubac_role_backup_snapshot', 'ubac_role_backup_handle_snapshot' );

/**
 * Handles the "take a snapshot now" button.
 *
 * @return void
 */
function ubac_role_backup_handle_snapshot() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You are not allowed to access this page.', 'dahuconcept-role-backup' ) );
    }

    check_admin_referer( 'ubac_role_backup_snapshot' );

    $result = ubac_role_backup_write( 'manual', ubac_role_backup_read_current(), array( 'evenement' => 'manuel' ) );

    if ( is_wp_error( $result ) ) {
        $args = array( 'ubac_msg' => 'erreur', 'ubac_detail' => rawurlencode( $result->get_error_message() ) );
    } elseif ( ! empty( $result['skipped'] ) ) {
        $args = array( 'ubac_msg' => 'identique' );
    } else {
        $args = array( 'ubac_msg' => 'instantane', 'ubac_detail' => rawurlencode( $result['file'] ) );
    }

    wp_safe_redirect( add_query_arg( $args, ubac_role_backup_url() ) );
    exit;
}

add_action( 'admin_post_ubac_role_backup_restore', 'ubac_role_backup_handle_restore' );

/**
 * Handles both restore forms.
 *
 * @return void
 */
function ubac_role_backup_handle_restore() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You are not allowed to access this page.', 'dahuconcept-role-backup' ) );
    }

    $file = isset( $_POST['fichier'] ) ? basename( wp_unslash( $_POST['fichier'] ) ) : '';

    check_admin_referer( 'ubac_role_backup_restore_' . $file );

    $full   = ! empty( $_POST['full'] );
    $result = ubac_role_backup_restore( $file, $full );

    if ( is_wp_error( $result ) ) {
        $args = array( 'ubac_msg' => 'erreur', 'ubac_detail' => rawurlencode( $result->get_error_message() ) );
    } else {
        $args = array(
            'ubac_msg'    => 'restaure',
            'ubac_detail' => rawurlencode( empty( $result['restaures'] ) ? __( '(no role to restore)', 'dahuconcept-role-backup' ) : implode( ', ', $result['restaures'] ) ),
            'ubac_mode'   => $result['mode'],
        );
    }

    wp_safe_redirect( add_query_arg( $args, ubac_role_backup_url() ) );
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
function ubac_role_backup_datetime_format() {
    return get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
}

/**
 * Feedback banner shown after an action.
 *
 * @return void
 */
function ubac_role_backup_render_notices() {
    if ( empty( $_GET['ubac_msg'] ) ) {
        return;
    }

    $msg    = sanitize_key( wp_unslash( $_GET['ubac_msg'] ) );
    $detail = isset( $_GET['ubac_detail'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['ubac_detail'] ) ) ) : '';
    $mode   = isset( $_GET['ubac_mode'] ) ? sanitize_key( wp_unslash( $_GET['ubac_mode'] ) ) : '';

    switch ( $msg ) {
        case 'instantane':
            printf(
                '<div class="notice notice-success is-dismissible"><p>%1$s <code>%2$s</code></p></div>',
                esc_html__( 'Snapshot created:', 'dahuconcept-role-backup' ),
                esc_html( $detail )
            );
            break;

        case 'identique':
            printf(
                '<div class="notice notice-info is-dismissible"><p>%s</p></div>',
                esc_html__( 'No snapshot created: the roles are identical to the last one recorded.', 'dahuconcept-role-backup' )
            );
            break;

        case 'restaure':
            // Two whole sentences rather than one sentence with the mode
            // interpolated: an adjective agrees differently in every language,
            // and a translator cannot fix that from a bare "full"/"additive".
            printf(
                '<div class="notice notice-success is-dismissible"><p>%1$s <strong>%2$s</strong></p></div>',
                'full' === $mode
                    ? esc_html__( 'Full restore done. Roles restored:', 'dahuconcept-role-backup' )
                    : esc_html__( 'Additive restore done. Roles restored:', 'dahuconcept-role-backup' ),
                esc_html( $detail )
            );
            break;

        case 'erreur':
            printf(
                '<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
                esc_html__( 'Failure:', 'dahuconcept-role-backup' ),
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
function ubac_role_backup_render_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You are not allowed to access this page.', 'dahuconcept-role-backup' ) );
    }

    echo '<div class="wrap ubac-ui">';
    echo '<div class="ubac-ui-head">';
    echo '<h1 class="ubac-ui-head__title">' . esc_html__( 'Role snapshots', 'dahuconcept-role-backup' ) . '</h1>';
    echo '<p class="ubac-ui-head__sub">' . esc_html__( 'A copy of every role, taken before anything can lose one.', 'dahuconcept-role-backup' ) . '</p>';
    echo '</div>';

    echo '<hr class="wp-header-end">';

    ubac_role_backup_render_notices();

    $preview = isset( $_GET['apercu'] ) ? basename( wp_unslash( $_GET['apercu'] ) ) : '';

    if ( $preview ) {
        ubac_role_backup_render_preview( $preview );
    } else {
        ubac_role_backup_render_overview();
    }

    echo '</div>';
}

/**
 * Main view: health status plus the table of snapshots.
 *
 * @return void
 */
function ubac_role_backup_render_overview() {
    $all     = ubac_role_backup_list();
    $current = ubac_role_backup_read_current();
    $age     = ubac_role_backup_last_age();
    $best    = ubac_role_backup_best_recent( $all );
    $next    = wp_next_scheduled( UBAC_ROLE_BACKUP_CRON );

    echo '<div class="ubac-ui-card"><div class="ubac-ui-card__head"><h2 class="ubac-ui-card__title">' . esc_html__( 'Status', 'dahuconcept-role-backup' ) . '</h2></div>';
    echo '<table class="widefat ubac-rb-table"><tbody>';

    /*
     * A site always has roles, so a count of zero means the option could not
     * be read rather than that there is nothing to back up. Say that, instead
     * of printing a dash with nothing after it.
     */
    printf(
        '<tr><th class="ubac-rb-col-label">%1$s</th><td><strong>%2$d</strong> — %3$s</td></tr>',
        esc_html__( 'Roles currently in the database', 'dahuconcept-role-backup' ),
        count( $current ),
        empty( $current )
            ? '<span class="ubac-rb-warning">' . esc_html__( 'the roles option could not be read', 'dahuconcept-role-backup' ) . '</span>'
            : esc_html( implode( ', ', ubac_role_backup_slugs( $current ) ) )
    );

    printf(
        '<tr><th>%1$s</th><td>%2$s</td></tr>',
        esc_html__( 'Last snapshot', 'dahuconcept-role-backup' ),
        null === $age
            ? '<span class="ubac-rb-warning">' . esc_html__( 'none', 'dahuconcept-role-backup' ) . '</span>'
            : esc_html(
                sprintf(
                    /* translators: 1: human-readable age, for example "3 days"; 2: snapshot file name. */
                    __( '%1$s ago (%2$s)', 'dahuconcept-role-backup' ),
                    human_time_diff( time() - $age ),
                    $all[0]['file']
                )
            )
    );

    printf(
        '<tr><th>%1$s</th><td>%2$s</td></tr>',
        esc_html__( 'Next daily snapshot', 'dahuconcept-role-backup' ),
        $next
            ? esc_html( wp_date( ubac_role_backup_datetime_format(), $next ) )
            : '<span class="ubac-rb-warning">' . esc_html__( 'not scheduled', 'dahuconcept-role-backup' ) . '</span>'
    );

    printf(
        '<tr><th>%1$s</th><td>%2$s</td></tr>',
        esc_html__( 'Snapshots kept', 'dahuconcept-role-backup' ),
        esc_html(
            sprintf(
                /* translators: 1: number of snapshots on disk; 2: daily retention limit; 3: on-change retention limit. */
                __( '%1$d (limit: %2$d daily + %3$d on change)', 'dahuconcept-role-backup' ),
                count( $all ),
                UBAC_ROLE_BACKUP_KEEP_DAILY,
                UBAC_ROLE_BACKUP_KEEP_CHANGE
            )
        )
    );

    printf(
        '<tr><th>%1$s</th><td>%2$s</td></tr>',
        esc_html__( 'Snapshot protected from pruning', 'dahuconcept-role-backup' ),
        $best
            ? esc_html(
                sprintf(
                    /* translators: 1: snapshot file name; 2: number of roles it holds. */
                    _n( '%1$s (%2$d role)', '%1$s (%2$d roles)', $best['count'], 'dahuconcept-role-backup' ),
                    $best['file'],
                    $best['count']
                )
            )
            : '—'
    );

    // Why that snapshot exists, when it exists because roles were about to be
    // erased. Read for this one snapshot only, not for the whole list: the
    // listing works from file names and reading thirty files to decorate a
    // table would be paid for on every screen load. This is the snapshot
    // somebody restores from, so this is the one worth a file read.
    $why = $best ? ubac_role_backup_rescue_reason( $best['file'] ) : '';

    if ( '' !== $why ) {
        printf(
            '<tr><th>%1$s</th><td><strong>%2$s</strong></td></tr>',
            esc_html__( 'Why it was taken', 'dahuconcept-role-backup' ),
            esc_html( $why )
        );
    }

    printf(
        '<tr><th>%1$s</th><td><code>%2$s</code></td></tr>',
        esc_html__( 'Directory', 'dahuconcept-role-backup' ),
        esc_html( ubac_role_backup_dir() )
    );

    echo '</tbody></table></div>';

    printf(
        '<div class="ubac-ui-card"><form method="post" action="%1$s" class="ubac-rb-form">%2$s<input type="hidden" name="action" value="ubac_role_backup_snapshot"><button type="submit" class="ubac-ui-btn ubac-ui-btn--go">%3$s</button></form></div>',
        esc_url( admin_url( 'admin-post.php' ) ),
        wp_nonce_field( 'ubac_role_backup_snapshot', '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where it is built, just above: esc_url(), esc_attr(), esc_js(), or markup returned by wp_nonce_field( ..., false ); %d for the count.
        esc_html__( 'Take a snapshot now', 'dahuconcept-role-backup' )
    );

    echo '<div class="ubac-ui-card"><div class="ubac-ui-card__head"><h2 class="ubac-ui-card__title">' . esc_html__( 'Snapshots', 'dahuconcept-role-backup' ) . '</h2></div>';

    if ( empty( $all ) ) {
        echo '<p class="ubac-ui-hint">' . esc_html__( 'No snapshot yet; the first one appears here as soon as you take one.', 'dahuconcept-role-backup' ) . '</p></div>';
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
    $rows     = $show_all ? $all : array_slice( $all, 0, UBAC_ROLE_BACKUP_LIST_LIMIT );

    echo '<table class="widefat striped ubac-rb-snapshots"><thead><tr>';
    printf(
        '<th>%1$s</th><th>%2$s</th><th class="ubac-rb-snapshots__num">%3$s</th><th>%4$s</th><th></th>',
        esc_html__( 'Date', 'dahuconcept-role-backup' ),
        esc_html__( 'Kind', 'dahuconcept-role-backup' ),
        esc_html__( 'Roles', 'dahuconcept-role-backup' ),
        esc_html__( 'File', 'dahuconcept-role-backup' )
    );
    echo '</tr></thead><tbody>';

    $labels = array(
        'daily'  => __( 'Daily', 'dahuconcept-role-backup' ),
        'change' => __( 'Change', 'dahuconcept-role-backup' ),
        'manual' => __( 'Manual', 'dahuconcept-role-backup' ),
    );

    $any_protected = false;

    foreach ( $rows as $meta ) {
        $url = add_query_arg(
            array( 'apercu' => $meta['file'] ),
            ubac_role_backup_url()
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
            '<tr><td>%1$s</td><td>%2$s</td><td class="ubac-rb-snapshots__num"><strong>%3$d</strong>%4$s</td><td><code>%5$s</code> <span class="ubac-rb-snapshots__size">%6$s</span></td><td class="ubac-rb-snapshots__action"><a href="%7$s">%8$s</a></td></tr>',
            esc_html( wp_date( ubac_role_backup_datetime_format(), $meta['timestamp'] ) ),
            esc_html( isset( $labels[ $meta['type'] ] ) ? $labels[ $meta['type'] ] : $meta['type'] ),
            $meta['count'], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where it is built, just above: esc_url(), esc_attr(), esc_js(), or markup returned by wp_nonce_field( ..., false ); %d for the count.
            $is_best
                ? ' <span class="ubac-rb-protected">&#9733;</span>'
                : '',
            esc_html( $meta['file'] ),
            esc_html( size_format( (int) $meta['size'] ) ),
            esc_url( $url ),
            esc_html__( 'Compare / restore', 'dahuconcept-role-backup' )
        );
    }

    echo '</tbody></table>';

    // A glyph in a table has a key, next to the table. This one had its
    // explanation in a panel further up the screen, which is no explanation at
    // all for somebody reading the row.
    if ( $any_protected ) {
        printf(
            '<p class="ubac-rb-legend"><span class="ubac-rb-protected">&#9733;</span> %s</p>',
            esc_html__( 'Kept whatever the retention limit says: it is the most complete snapshot, and the one a restore should usually start from.', 'dahuconcept-role-backup' )
        );
    }

    if ( ! $show_all && $total > count( $rows ) ) {
        printf(
            '<p class="ubac-rb-legend">%1$s <a href="%2$s">%3$s</a></p>',
            esc_html(
                sprintf(
                    /* translators: %s: how many older snapshots are not shown. */
                    _n( '%s older snapshot is not shown.', '%s older snapshots are not shown.', $total - count( $rows ), 'dahuconcept-role-backup' ),
                    number_format_i18n( $total - count( $rows ) )
                )
            ),
            esc_url( add_query_arg( 'all', '1', ubac_role_backup_url() ) ),
            esc_html__( 'Show every snapshot', 'dahuconcept-role-backup' )
        );
    }

    echo '</div>';
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
function ubac_role_backup_render_preview( $filename ) {
    $back = ubac_role_backup_url();

    $snapshot = ubac_role_backup_load( $filename );

    if ( is_wp_error( $snapshot ) ) {
        printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $snapshot->get_error_message() ) );
        printf(
            '<p><a href="%1$s">&larr; %2$s</a></p>',
            esc_url( $back ),
            esc_html__( 'Back', 'dahuconcept-role-backup' )
        );
        return;
    }

    $origin = ubac_role_backup_check_origin( $snapshot );
    $diff   = ubac_role_backup_diff( $snapshot );

    printf(
        '<p><a href="%1$s">&larr; %2$s</a></p>',
        esc_url( $back ),
        esc_html__( 'Back to the list', 'dahuconcept-role-backup' )
    );
    printf(
        '<div class="ubac-ui-card"><div class="ubac-ui-card__head"><h2 class="ubac-ui-card__title">%1$s <code>%2$s</code></h2></div>',
        esc_html__( 'Snapshot', 'dahuconcept-role-backup' ),
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
                _n( 'Created on %1$s — %2$d role', 'Created on %1$s — %2$d roles', count( $snapshot['roles'] ), 'dahuconcept-role-backup' ),
                $created ? wp_date( ubac_role_backup_datetime_format(), $created ) : '?',
                count( $snapshot['roles'] )
            )
        ),
        esc_html__( 'origin:', 'dahuconcept-role-backup' ),
        esc_html( isset( $snapshot['table_prefix'] ) ? $snapshot['table_prefix'] : '?' )
    );

    echo '</div>';

    if ( ! empty( $snapshot['roles_perdus'] ) ) {
        printf(
            '<div class="notice notice-warning inline"><p>%1$s <strong>%2$s</strong></p></div>',
            esc_html__( 'This snapshot was triggered by the disappearance of:', 'dahuconcept-role-backup' ),
            esc_html( implode( ', ', (array) $snapshot['roles_perdus'] ) )
        );
    }

    if ( is_wp_error( $origin ) ) {
        printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $origin->get_error_message() ) );
        return;
    }

    echo '<div class="ubac-ui-card"><div class="ubac-ui-card__head"><h2 class="ubac-ui-card__title">' . esc_html__( 'What the restore would change', 'dahuconcept-role-backup' ) . '</h2></div>';
    echo '<table class="widefat ubac-rb-table"><tbody>';

    printf(
        '<tr><th class="ubac-rb-col-label--wide">%1$s</th><td>%2$s</td></tr>',
        esc_html__( 'Roles to recreate (additive mode)', 'dahuconcept-role-backup' ),
        empty( $diff['manquants'] )
            ? '<em>' . esc_html__( 'none — nothing to restore', 'dahuconcept-role-backup' ) . '</em>'
            : '<strong>' . esc_html( implode( ', ', $diff['manquants'] ) ) . '</strong>'
    );

    printf(
        '<tr><th>%1$s</th><td>%2$s</td></tr>',
        esc_html__( 'Roles missing from this snapshot', 'dahuconcept-role-backup' ),
        empty( $diff['nouveaux'] )
            ? '<em>' . esc_html__( 'none', 'dahuconcept-role-backup' ) . '</em>'
            : esc_html( implode( ', ', $diff['nouveaux'] ) ) . ' <span class="ubac-rb-warning">' . esc_html__( '(kept in additive mode, DELETED in full mode)', 'dahuconcept-role-backup' ) . '</span>'
    );

    printf(
        '<tr><th>%1$s</th><td>%2$s</td></tr>',
        esc_html__( 'Roles already present', 'dahuconcept-role-backup' ),
        esc_html( implode( ', ', $diff['communs'] ) )
    );

    echo '</tbody></table></div>';

    $nonce  = wp_nonce_field( 'ubac_role_backup_restore_' . $snapshot['file'], '_wpnonce', true, false );
    $action = esc_url( admin_url( 'admin-post.php' ) );
    $file   = esc_attr( $snapshot['file'] );

    // TWO separate forms, rather than a single one carrying a checkbox: with
    // one form, ticking "full replacement" and then clicking the additive
    // button would still send full=1. The most destructive mode must never be
    // reachable through the most innocuous button.
    printf(
        '<div class="ubac-ui-card"><form method="post" action="%1$s" class="ubac-rb-form--spaced">%2$s
        <input type="hidden" name="action" value="ubac_role_backup_restore">
        <input type="hidden" name="fichier" value="%3$s">
        <p><button type="submit" class="ubac-ui-btn ubac-ui-btn--go"%4$s>%5$s</button></p>
        </form></div>',
        $action, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where it is built, just above: esc_url(), esc_attr(), esc_js(), or markup returned by wp_nonce_field( ..., false ); %d for the count.
        $nonce, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where it is built, just above: esc_url(), esc_attr(), esc_js(), or markup returned by wp_nonce_field( ..., false ); %d for the count.
        $file, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where it is built, just above: esc_url(), esc_attr(), esc_js(), or markup returned by wp_nonce_field( ..., false ); %d for the count.
        empty( $diff['manquants'] ) ? ' disabled' : '',
        esc_html__( 'Restore the missing roles (additive)', 'dahuconcept-role-backup' )
    );

    $lost = empty( $diff['nouveaux'] ) ? '' : ': ' . implode( ', ', $diff['nouveaux'] );

    // esc_js(), not esc_html(): this string is injected into a JavaScript
    // string literal inside an HTML attribute. A translation containing an
    // apostrophe — which French, Italian and many others will — would close
    // the literal early and break the button without it.
    $confirm = esc_js( __( 'Full replacement of the roles. This action cannot be undone without a restore. Confirm?', 'dahuconcept-role-backup' ) );

    printf(
        '<div class="ubac-ui-card"><div class="ubac-ui-card__head"><h2 class="ubac-ui-card__title">%4$s</h2></div><form method="post" action="%1$s" class="ubac-rb-form--danger">%2$s
        <input type="hidden" name="action" value="ubac_role_backup_restore">
        <input type="hidden" name="fichier" value="%3$s">
        <input type="hidden" name="full" value="1">
        <p>%5$s <strong class="ubac-rb-warning">%6$s</strong></p>
        <p><label><input type="checkbox" onclick="document.getElementById(\'ubac-full\').disabled = !this.checked;"> %7$s</label></p>
        <p><button type="submit" id="ubac-full" class="ubac-ui-btn" disabled onclick="return confirm(\'%8$s\');">%9$s</button></p>
        </form></div>',
        $action, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where it is built, just above: esc_url(), esc_attr(), esc_js(), or markup returned by wp_nonce_field( ..., false ); %d for the count.
        $nonce, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where it is built, just above: esc_url(), esc_attr(), esc_js(), or markup returned by wp_nonce_field( ..., false ); %d for the count.
        $file, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where it is built, just above: esc_url(), esc_attr(), esc_js(), or markup returned by wp_nonce_field( ..., false ); %d for the count.
        esc_html__( 'Full replacement', 'dahuconcept-role-backup' ),
        esc_html__( 'Overwrites the whole set of roles with this snapshot.', 'dahuconcept-role-backup' ),
        esc_html(
            sprintf(
                /* translators: %s: empty, or a colon followed by the comma-separated roles that would be deleted. */
                __( 'Every role created since will be permanently deleted%s.', 'dahuconcept-role-backup' ),
                $lost
            )
        ),
        esc_html__( 'I understand and I confirm.', 'dahuconcept-role-backup' ),
        $confirm, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where it is built, just above: esc_url(), esc_attr(), esc_js(), or markup returned by wp_nonce_field( ..., false ); %d for the count.
        esc_html__( 'Replace in full', 'dahuconcept-role-backup' )
    );
}
