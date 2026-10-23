<?php
/**
 * Storage: where snapshots live, the dedicated log, and retention.
 *
 * @package DahuRoleBackup
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Option location and name
 * ---------------------------------------------------------------------- */

/**
 * Real name of the roles option for THIS installation.
 *
 * Derived from the table prefix rather than hard-coded: the same file then
 * works unchanged on installations that share one database under several
 * distinct table prefixes, as a production site and its staging copy often do.
 *
 * @return string Option name, for example wp_user_roles.
 */
function dahu_role_backup_option_name() {
    global $wpdb;
    return $wpdb->get_blog_prefix() . 'user_roles';
}

/**
 * Snapshot directory.
 *
 * Deliberately under wp-content/ and NOT in uploads/: the uploads directory is
 * served by the web server and swept up by synchronisation tools. A map of
 * roles and capabilities is not a secret, but it has no business sitting in a
 * public directory.
 *
 * @return string Absolute path, no trailing slash.
 */
function dahu_role_backup_dir() {

    $default = WP_CONTENT_DIR . '/dahu-role-backups';

    /**
     * Filters the directory holding the snapshots.
     *
     * Deliberately under wp-content/ rather than uploads/: the uploads
     * directory is served by the web server and swept up by synchronisation
     * tools. A map of roles and capabilities is not a secret, but it has no
     * business sitting in a public directory.
     *
     * Some managed hosts make wp-content/ read-only. When the default cannot
     * be created, the plugin falls back to the uploads directory rather than
     * failing silently — a protected snapshot in a less ideal place beats no
     * snapshot at all. Both locations get an .htaccess and an index.php.
     *
     * @since 2.0.0
     *
     * @param string $dir Absolute path, no trailing slash.
     */
    $dir = (string) apply_filters( 'dahu_role_backup_directory', $default );

    if ( $dir !== $default ) {
        return untrailingslashit( $dir );
    }

    if ( is_dir( $dir ) || wp_mkdir_p( $dir ) ) {
        return $dir;
    }

    $uploads = wp_upload_dir();

    if ( ! empty( $uploads['basedir'] ) && empty( $uploads['error'] ) ) {
        return untrailingslashit( $uploads['basedir'] ) . '/dahu-role-backups';
    }

    return $dir;
}

/**
 * Creates the directory when needed and drops the two access guards into it.
 *
 * .htaccess covers Apache; index.php covers the case where the server ignores
 * .htaccess (nginx) by at least preventing a directory listing.
 *
 * @return bool True when the directory exists and is writable.
 */
function dahu_role_backup_ensure_dir() {
    $dir = dahu_role_backup_dir();

    if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
        return false;
    }

    $htaccess = $dir . '/.htaccess';
    if ( ! file_exists( $htaccess ) ) {
        @file_put_contents( $htaccess, "Order deny,allow\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" );
    }

    $index = $dir . '/index.php';
    if ( ! file_exists( $index ) ) {
        @file_put_contents( $index, "<?php\n// Silence.\n" );
    }

    return is_dir( $dir ) && is_writable( $dir );
}

/* -------------------------------------------------------------------------
 * Reading and reloading the roles past every cache
 * ---------------------------------------------------------------------- */

/**
 * Reads the roles option straight from the options table.
 *
 * get_option() is served from the object cache, so on a site with Redis or
 * Memcached it can answer with a copy minutes old. Everything this plugin
 * does — freezing a state, diffing a snapshot, deciding what to restore —
 * is worthless if it is reasoning about a copy rather than about the table.
 *
 * @return array Roles as stored, or an empty array when the row is missing.
 */
function dahu_role_backup_read_db_roles() {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reading past the object cache is the entire point.
    $raw = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            dahu_role_backup_option_name()
        )
    );

    if ( null === $raw ) {
        return array();
    }

    $value = maybe_unserialize( $raw );

    return is_array( $value ) ? $value : array();
}

/**
 * Refills the in-memory roles object from the table.
 *
 * WordPress keeps every role in ONE option, and WP_Roles holds a copy of it
 * in memory. Every write of that option — add_role(), remove_role(),
 * WP_Role::add_cap() — rewrites the WHOLE option from that copy. A copy that
 * is out of date therefore does not merely fail to see a role: it ERASES it,
 * because the role is absent from the array being written back.
 *
 * Which makes this call mandatory on a restore path, in both directions. A
 * restore that adds a role through add_role() while the in-memory copy is
 * stale writes the stale array plus one role, deleting whatever the copy had
 * not heard about. And a restore that writes the option directly leaves that
 * copy stale behind it, so the next add_cap() of the same request undoes the
 * restore. A rescue tool that loses roles while restoring them is worse than
 * no rescue tool.
 *
 * The site is named before reading as well as after: for_site() with no
 * argument recalibrates role_key on the CURRENT site, so a network plugin
 * that switched blogs without restoring would otherwise have this reading
 * site A while the write went to site B.
 *
 * @return void
 */
function dahu_role_backup_reload_roles() {
    $roles   = function_exists( 'wp_roles' ) ? wp_roles() : null;
    $site_id = get_current_blog_id();

    if ( is_object( $roles ) && method_exists( $roles, 'for_site' ) ) {
        $roles->for_site( $site_id );
    }

    wp_cache_delete( 'alloptions', 'options' );
    wp_cache_delete( 'notoptions', 'options' );
    wp_cache_delete( dahu_role_backup_option_name(), 'options' );

    // And again, to load back what the table really holds now that the cache
    // no longer stands in the way.
    if ( is_object( $roles ) && method_exists( $roles, 'for_site' ) ) {
        $roles->for_site( $site_id );
    }
}

/* -------------------------------------------------------------------------
 * Logging
 * ---------------------------------------------------------------------- */

/**
 * Write to this plugin's own log file.
 *
 * Deliberately not the general PHP error log: on a busy site that log is
 * drowned in other plugins' noise, and a role quietly disappearing is exactly
 * the kind of event that would be lost in it. A dedicated file keeps the
 * history readable, which is the whole point of having one.
 *
 * Rotated once past 2 MB, so the file cannot grow without bound.
 *
 * Log lines are written in English and are deliberately NOT translatable: a
 * technical journal is read while diagnosing an incident, often by someone
 * other than the site's own administrator, and it is more useful when its
 * wording does not depend on the locale that happened to be active.
 *
 * @param string $message The line to record.
 * @return void
 */
function dahu_role_backup_log( $message ) {
    $file = dahu_role_backup_dir() . '/backup.log';

    if ( file_exists( $file ) && filesize( $file ) > 2 * 1024 * 1024 ) {
        @rename( $file, $file . '.1' );
    }

    $line = '[' . gmdate( 'Y-m-d H:i:s' ) . ' UTC] ' . $message . "\n";

    // Falling back to the PHP log is better than losing the line entirely.
    if ( ! @error_log( $line, 3, $file ) ) {
        error_log( '[Dahu Role Backup] ' . $message );
    }
}

/**
 * A short description of what triggered the current request.
 *
 * Recorded with every snapshot so a cron run can be told apart from something
 * a person did, which is the first question asked when reading the log back.
 *
 * @return string
 */
function dahu_role_backup_context() {
    $bits = array();

    if ( defined( 'WP_CLI' ) && WP_CLI ) {
        $bits[] = 'WP-CLI';
    }
    if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
        $bits[] = 'CRON';
    }
    if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
        $bits[] = 'AJAX';
    }
    if ( is_admin() ) {
        $bits[] = 'ADMIN';
    }
    if ( empty( $bits ) ) {
        $bits[] = 'FRONT';
    }

    $user = 'not-logged-in';
    if ( function_exists( 'wp_get_current_user' ) ) {
        $u = wp_get_current_user();
        if ( $u && ! empty( $u->user_login ) ) {
            $user = $u->user_login . '#' . $u->ID;
        }
    }

    return 'context=' . implode( '+', $bits ) . ' | user=' . $user;
}


/* -------------------------------------------------------------------------
 * Retention
 * ---------------------------------------------------------------------- */

/**
 * Prunes the surplus snapshots.
 *
 * Base rule: the N most recent of each kind are kept.
 *
 * IMPORTANT EXCEPTION: the snapshot holding the MOST roles over the recent
 * window is never deleted. Without it, a run of repeated disappearances would
 * rotate the retention until no kept copy held the lost roles any more: the
 * safety net would empty itself, silently, in precisely the scenario it exists
 * to cover. On a tie in role count the oldest wins: that is the one reaching
 * furthest back before the incident.
 *
 * @return array<int, string> File names actually deleted.
 */
function dahu_role_backup_prune() {
    $all = dahu_role_backup_list();

    if ( empty( $all ) ) {
        return array();
    }

    $limits = array(
        'daily'  => DAHU_ROLE_BACKUP_KEEP_DAILY,
        'change' => DAHU_ROLE_BACKUP_KEEP_CHANGE,
        'manual' => DAHU_ROLE_BACKUP_KEEP_CHANGE,
    );

    // Protected snapshot: the richest in roles over the window.
    $protected = dahu_role_backup_best_recent( $all );

    $seen    = array();
    $deleted = array();

    foreach ( $all as $meta ) {
        $type = $meta['type'];

        if ( ! isset( $seen[ $type ] ) ) {
            $seen[ $type ] = 0;
        }
        $seen[ $type ]++;

        $limit = isset( $limits[ $type ] ) ? $limits[ $type ] : DAHU_ROLE_BACKUP_KEEP_CHANGE;

        if ( $seen[ $type ] <= $limit ) {
            continue;
        }

        if ( $protected && $meta['file'] === $protected['file'] ) {
            dahu_role_backup_log( sprintf(
                'PURGE: %1$s kept despite the limit (best recent snapshot, %2$d roles).',
                $meta['file'],
                $meta['count']
            ) );
            continue;
        }

        if ( @unlink( $meta['path'] ) ) {
            $deleted[] = $meta['file'];
        }
    }

    if ( ! empty( $deleted ) ) {
        dahu_role_backup_log( sprintf(
            'PURGE: snapshots deleted (%1$d): %2$s',
            count( $deleted ),
            implode( ', ', $deleted )
        ) );
    }

    return $deleted;
}

/**
 * The snapshot richest in roles over the protection window.
 *
 * On a tie the oldest is kept, because it reaches furthest back.
 *
 * @param array<int, array>|null $all Inventory to search, or null to read it.
 * @return array|null Snapshot metadata, or null when the window is empty.
 */
function dahu_role_backup_best_recent( $all = null ) {
    if ( null === $all ) {
        $all = dahu_role_backup_list();
    }

    $floor = time() - ( DAHU_ROLE_BACKUP_BEST_WINDOW_DAYS * DAY_IN_SECONDS );
    $best  = null;

    foreach ( $all as $meta ) {
        if ( $meta['timestamp'] < $floor ) {
            continue;
        }
        if ( null === $best
            || $meta['count'] > $best['count']
            || ( $meta['count'] === $best['count'] && $meta['timestamp'] < $best['timestamp'] ) ) {
            $best = $meta;
        }
    }

    return $best;
}
