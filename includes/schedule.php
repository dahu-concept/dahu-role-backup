<?php
/**
 * Triggers, plugin lifecycle and health checks.
 *
 * @package DahuRoleBackup
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Triggers
 * ---------------------------------------------------------------------- */

/**
 * Daily snapshot (cron).
 *
 * @return void
 */
add_action( DAHU_ROLE_BACKUP_CRON, 'dahu_role_backup_run_daily' );
function dahu_role_backup_run_daily() {
    $roles = dahu_role_backup_read_current();

    if ( empty( $roles ) ) {
        dahu_role_backup_log( 'ALERT: the roles option is empty or unreadable, daily snapshot cancelled.' );
        return;
    }

    dahu_role_backup_write( 'daily', $roles );
}

/**
 * Immediate snapshot as soon as the LIST of roles changes.
 *
 * Why this trigger on top of the daily one: the daily snapshot alone can let up
 * to 24 hours pass between a role disappearing and that being noticed, and the
 * next snapshot then freezes the state that is ALREADY missing it.
 *
 * Why only the list of slugs is compared: plugins exist that rewrite the whole
 * roles option dozens of times per second through WP_Role::add_cap() without
 * ever touching the list of roles. Comparing the full contents would therefore
 * trigger a snapshot on every one of those calls. The real cost of this hook
 * comes down, in the overwhelming majority of cases, to two array_keys() and
 * one array_diff() over a dozen or so entries.
 *
 * update_option_{option} is used because it receives the old value AND the new
 * one: no state has to be remembered between two requests.
 *
 * @return void
 */
add_action( 'plugins_loaded', 'dahu_role_backup_register_watcher', 1 );
function dahu_role_backup_register_watcher() {
    add_action( 'update_option_' . dahu_role_backup_option_name(), 'dahu_role_backup_on_option_update', 10, 3 );
}

/**
 * Freezes the roles when the list of slugs changes.
 *
 * @param mixed  $old_value Option value before the write.
 * @param mixed  $new_value Option value after the write.
 * @param string $option    Option name.
 * @return void
 */
function dahu_role_backup_on_option_update( $old_value, $new_value, $option ) {
    if ( ! is_array( $old_value ) || ! is_array( $new_value ) ) {
        return;
    }

    $before = dahu_role_backup_slugs( $old_value );
    $after  = dahu_role_backup_slugs( $new_value );

    $removed = array_values( array_diff( $before, $after ) );
    $added   = array_values( array_diff( $after, $before ) );

    if ( empty( $removed ) && empty( $added ) ) {
        return; // By far the most frequent case: leave immediately.
    }

    // Preservation comes first: when roles disappear, it is the state BEFORE
    // that has value, not the one after. The old value is therefore the one
    // frozen. The reduced state will be captured anyway by the next daily
    // snapshot.
    if ( ! empty( $removed ) ) {
        dahu_role_backup_log( sprintf(
            '!!! ROLES DELETED !!! %1$s | before (%2$d): %3$s | after (%4$d): %5$s | %6$s',
            implode( ', ', $removed ),
            count( $before ),
            implode( ', ', $before ),
            count( $after ),
            implode( ', ', $after ),
            dahu_role_backup_context()
        ) );

        dahu_role_backup_write( 'change', $old_value, array(
            'evenement'    => 'roles_supprimes',
            'roles_perdus' => $removed,
        ) );

        return;
    }

    dahu_role_backup_log( sprintf(
        'Roles added: %1$s | total (%2$d) | %3$s',
        implode( ', ', $added ),
        count( $after ),
        dahu_role_backup_context()
    ) );

    dahu_role_backup_write( 'change', $new_value, array(
        'evenement'     => 'roles_ajoutes',
        'roles_ajoutes' => $added,
    ) );
}


/* =========================================================
 * PLUGIN LIFECYCLE
 *
 * Hooked through register_activation_hook() and
 * register_deactivation_hook(), not registered as separate hooks.
 * ========================================================= */

/**
 * Prepares the directory, schedules the cron and takes a first snapshot.
 *
 * @return void
 */
function dahu_role_backup_activate() {
    dahu_role_backup_ensure_dir();

    if ( ! wp_next_scheduled( DAHU_ROLE_BACKUP_CRON ) ) {
        // Offset slightly so it does not line up with the site's other daily
        // tasks.
        wp_schedule_event( time() + 300, 'daily', DAHU_ROLE_BACKUP_CRON );
    }

    // An immediate first snapshot: the plugin has to be useful from the second
    // after it is activated, not from the first cron run the next day.
    $roles = dahu_role_backup_read_current();

    if ( ! empty( $roles ) ) {
        dahu_role_backup_write( 'manual', $roles, array( 'evenement' => 'activation' ) );
    }
}


/**
 * Unschedules the cron and keeps every snapshot.
 *
 * @return void
 */
function dahu_role_backup_deactivate() {
    $timestamp = wp_next_scheduled( DAHU_ROLE_BACKUP_CRON );

    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, DAHU_ROLE_BACKUP_CRON );
    }

    // Snapshots are NEVER deleted on deactivation: they have to survive the
    // plugin being deactivated by accident, which is precisely one of the
    // scenarios they protect against.
    dahu_role_backup_log( 'Plugin deactivated. Existing snapshots are kept.' );
}
/**
 * Defensive rescheduling: when the cron event has gone missing (a database
 * restore, a migration, a partial deactivation), it is put back without waiting
 * for someone to deactivate and reactivate the plugin by hand.
 *
 * @return void
 */
add_action( 'admin_init', 'dahu_role_backup_maybe_reschedule' );
function dahu_role_backup_maybe_reschedule() {
    if ( ! wp_next_scheduled( DAHU_ROLE_BACKUP_CRON ) ) {
        wp_schedule_event( time() + 300, 'daily', DAHU_ROLE_BACKUP_CRON );
        dahu_role_backup_log( 'Daily task missing: rescheduled automatically.' );
    }
}

/* -------------------------------------------------------------------------
 * Health
 * ---------------------------------------------------------------------- */

/**
 * Age of the most recent snapshot, in seconds.
 *
 * @return int|null Age in seconds, or null when there is no snapshot at all.
 */
function dahu_role_backup_last_age() {
    $all = dahu_role_backup_list();

    if ( empty( $all ) ) {
        return null;
    }

    return time() - $all[0]['timestamp'];
}

/**
 * Warns when the last backup is too old.
 *
 * A plugin can be deactivated, or its cron stop running, without anyone
 * noticing: the safety net would then vanish silently and only be found missing
 * at the moment it was needed. This warning makes the failure visible.
 *
 * @return void
 */
add_action( 'admin_notices', 'dahu_role_backup_stale_notice' );
function dahu_role_backup_stale_notice() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $age = dahu_role_backup_last_age();

    if ( null !== $age && $age < DAHU_ROLE_BACKUP_STALE_HOURS * HOUR_IN_SECONDS ) {
        return;
    }

    $url = dahu_role_backup_url();

    if ( null === $age ) {
        $state = esc_html__( 'no role snapshot has been recorded yet.', 'dahu-role-backup' );
    } else {
        $state = esc_html(
            sprintf(
                /* translators: %s: human-readable age of the last snapshot, for example "3 days". */
                __( 'the last role snapshot is %s old. Roles are therefore no longer protected.', 'dahu-role-backup' ),
                human_time_diff( time() - $age )
            )
        );
    }

    $link = sprintf(
        '<a href="%1$s">%2$s</a>',
        esc_url( $url ),
        esc_html__( 'Open the snapshots page', 'dahu-role-backup' )
    );

    printf(
        '<div class="notice notice-warning"><p><strong>Dahu Role Backup</strong> — %1$s %2$s</p></div>',
        $state,
        sprintf(
            /* translators: %s: link to the snapshots page, already marked up as an <a> element. */
            __( '%s to take one now.', 'dahu-role-backup' ),
            $link
        )
    );
}

