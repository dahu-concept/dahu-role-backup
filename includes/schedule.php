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

    // PHP_INT_MAX: last, so the value seen is the one that will be written
    // after every other filter has had its say.
    add_filter( 'pre_update_option_' . dahu_role_backup_option_name(), 'dahu_role_backup_before_option_write', PHP_INT_MAX, 3 );
}

/**
 * The write that the watcher above cannot see, and the reason this one exists.
 *
 * `update_option_{option}` receives `$old_value` — and `$old_value` comes from
 * the `get_option()` of the SAME request. That is the whole problem, and it is
 * not a theoretical one:
 *
 *     t0  request A starts and loads the roles into memory (no `sav` yet)
 *     t1  request B creates the role `sav` and writes it to the database
 *     t2  request A calls add_cap() -> rewrites ITS copy from t0
 *         -> `sav` is erased, silently
 *
 * `WP_Role::add_cap()` does not write one capability. It hands over to
 * `WP_Roles::add_cap()`, which writes **the entire roles array** from the
 * in-memory copy loaded once at the start of the request. So adding a single
 * capability rewrites the whole table from a copy that may be minutes old. No
 * object cache is needed for this; a persistent one only widens the window.
 *
 * In that request, `$old_value` and `$new_value` BOTH lack `sav`. The diff is
 * empty, the watcher above leaves immediately, and no snapshot is written —
 * while the role really does disappear from the database. Diagnosed in
 * production on a shop where a premium plugin called `add_cap()` dozens of
 * times per second on `admin-ajax.php`; the first version of the diagnostic
 * tool had this same blindness and recorded nothing for weeks.
 *
 * So this filter compares the incoming value with **the database, read
 * directly**, past the object cache. That is the only reading that knows what
 * is about to be lost.
 *
 * ## What it costs
 *
 * One `SELECT` per **real** write of the roles option, and nothing at all
 * otherwise. The no-op writes — the capability is already there, the value is
 * unchanged — leave on a string comparison before any SQL, which matters
 * because those are the frequent ones: `pre_update_option` fires *before*
 * `update_option()`'s own short-circuit, so without that exit this filter
 * would run a query on every one of those dozens of calls per second.
 *
 * ## What it never does
 *
 * Modify the value. It returns exactly what it was given, including when
 * something fails: a backup plugin that corrupted a roles write while trying
 * to protect it would be worse than no backup plugin. Everything is wrapped,
 * and a failure goes to the log and nowhere else.
 *
 * @param mixed  $value     Value about to be written.
 * @param mixed  $old_value Value as this request read it. Deliberately not trusted.
 * @param string $option    Option name.
 * @return mixed $value, untouched.
 */
function dahu_role_backup_before_option_write( $value, $old_value, $option ) {
    try {
        if ( ! is_array( $value ) ) {
            return $value;
        }

        // A write that changes nothing, which is the common case on a shop
        // where something calls add_cap() in a loop. Compared serialised
        // because two role arrays differ by key order often enough that `==`
        // would be a lie in both directions.
        if ( maybe_serialize( $value ) === maybe_serialize( $old_value ) ) {
            return $value;
        }

        global $wpdb;

        $raw = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reading past the object cache is the entire point.
            $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option )
        );

        if ( null === $raw ) {
            return $value;
        }

        $database = maybe_unserialize( $raw );

        if ( ! is_array( $database ) || empty( $database ) ) {
            return $value;
        }

        $lost = array_values( array_diff( dahu_role_backup_slugs( $database ), dahu_role_backup_slugs( $value ) ) );

        if ( empty( $lost ) ) {
            return $value;
        }

        // Something is about to be lost. Now: was it meant?
        //
        // A deliberate removal — our own screen, a plugin cleaning up on
        // uninstall — happens in a request whose copy matches the database,
        // and `update_option_{option}` already logs it and freezes the state
        // before it. Saying it twice, once in alarming words, would teach a
        // shop to ignore the alarming words.
        //
        // A stale copy is the other case, and it is the one nobody ever sees:
        // the request did not intend to remove anything, it simply did not
        // know the role existed.
        $stale = maybe_serialize( $old_value ) !== $raw;

        if ( ! $stale ) {
            return $value;
        }

        dahu_role_backup_log( sprintf(
            '!!! ROLES ABOUT TO BE LOST BY A STALE COPY !!! %1$s | in the database (%2$d): %3$s | in the write (%4$d): %5$s | %6$s',
            implode( ', ', $lost ),
            count( dahu_role_backup_slugs( $database ) ),
            implode( ', ', dahu_role_backup_slugs( $database ) ),
            count( dahu_role_backup_slugs( $value ) ),
            implode( ', ', dahu_role_backup_slugs( $value ) ),
            dahu_role_backup_context()
        ) );

        // The database value, not the one this request holds: the request's
        // copy is the one that has already lost the role.
        dahu_role_backup_write( 'change', $database, array(
            'evenement'    => 'copie_perimee',
            'roles_perdus' => $lost,
        ) );
    } catch ( Throwable $e ) {
        dahu_role_backup_log( sprintf( 'internal error ignored while watching a roles write: %s (%s:%d)', $e->getMessage(), $e->getFile(), $e->getLine() ) );
    }

    return $value;
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

