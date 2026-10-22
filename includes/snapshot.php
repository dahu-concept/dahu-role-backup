<?php
/**
 * Taking a snapshot, and reading one back.
 *
 * @package DahuRoleBackup
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Core: taking a snapshot
 * ---------------------------------------------------------------------- */

/**
 * Fingerprint of the roles' contents.
 *
 * Used for deduplication: two rigorously identical snapshots are never written
 * twice. Key order is normalised before hashing, otherwise a mere reordering of
 * the array by another plugin would produce a different fingerprint for
 * identical content.
 *
 * @param array $roles Roles array to fingerprint.
 * @return string SHA-1 of the normalised roles, or '' when the input is not an array.
 */
function dahu_role_backup_checksum( $roles ) {
    if ( ! is_array( $roles ) ) {
        return '';
    }

    $normalized = $roles;
    ksort( $normalized );

    foreach ( $normalized as $slug => $data ) {
        if ( isset( $data['capabilities'] ) && is_array( $data['capabilities'] ) ) {
            ksort( $data['capabilities'] );
            $normalized[ $slug ] = $data;
        }
    }

    return sha1( wp_json_encode( $normalized ) );
}

/**
 * Sorted list of role slugs.
 *
 * @param array $roles Roles array, keyed by slug.
 * @return array<int, string> Sorted slugs.
 */
function dahu_role_backup_slugs( $roles ) {
    if ( ! is_array( $roles ) ) {
        return array();
    }
    $slugs = array_keys( $roles );
    sort( $slugs );
    return $slugs;
}

/**
 * File name of a snapshot.
 *
 * The kind, the timestamp, the role count and the short fingerprint are all
 * encoded IN the name. That is deliberate: pruning and the search for the
 * "best snapshot" can then work without opening a single file, which matters
 * once the directory holds sixty of them.
 *
 * @param string $type     daily | change | manual.
 * @param int    $count    Number of roles held.
 * @param string $checksum Full fingerprint; only its first 8 characters are used.
 * @return string File name, extension included.
 */
function dahu_role_backup_filename( $type, $count, $checksum ) {
    return sprintf(
        'dahu-roles-%s-%s-r%d-%s.json',
        $type,
        gmdate( 'Ymd-His' ),
        (int) $count,
        substr( $checksum, 0, 8 )
    );
}

/**
 * Breaks a snapshot file name apart.
 *
 * Returns false when the name does not match the expected format: any foreign
 * file dropped into the directory is therefore ignored, and above all never
 * deleted by pruning.
 *
 * @param string $filename Bare file name, no path.
 * @return array|false Metadata (file, type, timestamp, count, checksum), or false.
 */
function dahu_role_backup_parse_filename( $filename ) {
    $pattern = '/^dahu-roles-(daily|change|manual)-(\d{8})-(\d{6})-r(\d+)-([0-9a-f]{8})\.json$/';

    if ( ! preg_match( $pattern, $filename, $m ) ) {
        return false;
    }

    $timestamp = strtotime( $m[2] . 'T' . substr( $m[3], 0, 2 ) . ':' . substr( $m[3], 2, 2 ) . ':' . substr( $m[3], 4, 2 ) . '+00:00' );

    return array(
        'file'      => $filename,
        'type'      => $m[1],
        'timestamp' => $timestamp ? $timestamp : 0,
        'count'     => (int) $m[4],
        'checksum'  => $m[5],
    );
}

/**
 * Inventory of the snapshots present, newest first.
 *
 * @param string|null $type Restrict to one kind, or null for all of them.
 * @return array<int, array> Metadata, each entry with its path and size added.
 */
function dahu_role_backup_list( $type = null ) {
    $dir = dahu_role_backup_dir();

    if ( ! is_dir( $dir ) ) {
        return array();
    }

    $out = array();

    foreach ( (array) scandir( $dir ) as $entry ) {
        $meta = dahu_role_backup_parse_filename( $entry );
        if ( ! $meta ) {
            continue;
        }
        if ( null !== $type && $meta['type'] !== $type ) {
            continue;
        }
        $meta['path'] = $dir . '/' . $entry;
        $meta['size'] = @filesize( $meta['path'] );
        $out[]        = $meta;
    }

    usort( $out, function ( $a, $b ) {
        if ( $a['timestamp'] === $b['timestamp'] ) {
            return strcmp( $b['file'], $a['file'] );
        }
        return ( $a['timestamp'] < $b['timestamp'] ) ? 1 : -1;
    } );

    return $out;
}

/**
 * Writes a snapshot.
 *
 * @param string $type  daily | change | manual.
 * @param array  $roles Roles array to freeze.
 * @param array  $extra Extra metadata (roles lost or added, and so on).
 * @return array|WP_Error Result array, or WP_Error when nothing could be written.
 */
function dahu_role_backup_write( $type, $roles, $extra = array() ) {
    if ( ! is_array( $roles ) || empty( $roles ) ) {
        return new WP_Error( 'dahu_role_backup_vide', __( 'Refusing to write an empty or invalid snapshot.', 'dahu-role-backup' ) );
    }

    if ( ! dahu_role_backup_ensure_dir() ) {
        return new WP_Error( 'dahu_role_backup_dir', __( 'Snapshot directory missing or not writable: ', 'dahu-role-backup' ) . dahu_role_backup_dir() );
    }

    $checksum = dahu_role_backup_checksum( $roles );
    $existing = dahu_role_backup_list();

    // Deduplication: when the most recent snapshot carries the same
    // fingerprint, nothing has changed and nothing is written. Indispensable
    // here, because some plugins trigger a write of the roles option dozens of
    // times per second without the list of roles ever changing.
    if ( ! empty( $existing ) && substr( $checksum, 0, 8 ) === $existing[0]['checksum'] ) {
        return array(
            'skipped' => true,
            'file'    => $existing[0]['file'],
            'reason'  => __( 'identical to the last snapshot', 'dahu-role-backup' ),
        );
    }

    global $wpdb;

    $payload = array_merge(
        array(
            'format'       => 1,
            'generateur'   => 'dahu-role-backup ' . DAHU_ROLE_BACKUP_VERSION,
            'type'         => $type,
            'date_utc'     => gmdate( 'c' ),
            'site_url'     => get_site_url(),
            'table_prefix' => $wpdb->get_blog_prefix(),
            'option_name'  => dahu_role_backup_option_name(),
            'role_count'   => count( $roles ),
            'role_slugs'   => dahu_role_backup_slugs( $roles ),
            'checksum'     => $checksum,
            'contexte'     => dahu_role_backup_context(),
        ),
        $extra,
        array( 'roles' => $roles )
    );

    $filename = dahu_role_backup_filename( $type, count( $roles ), $checksum );
    $path     = dahu_role_backup_dir() . '/' . $filename;

    $json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

    if ( false === $json || false === @file_put_contents( $path, $json ) ) {
        return new WP_Error( 'dahu_role_backup_ecriture', __( 'Could not write ', 'dahu-role-backup' ) . $path );
    }

    update_option( 'dahu_role_backup_last', array(
        'file'     => $filename,
        'time'     => time(),
        'count'    => count( $roles ),
        'checksum' => $checksum,
    ), false );

    dahu_role_backup_log( sprintf(
        'SNAPSHOT %1$s: %2$s (%3$d roles: %4$s) | %5$s',
        strtoupper( $type ),
        $filename,
        count( $roles ),
        implode( ', ', dahu_role_backup_slugs( $roles ) ),
        dahu_role_backup_context()
    ) );

    dahu_role_backup_prune();

    return array(
        'skipped' => false,
        'file'    => $filename,
        'path'    => $path,
        'count'   => count( $roles ),
    );
}

/**
 * Reads the roles option straight from the database.
 *
 * wp_roles() is deliberately short-circuited: that object is loaded once per
 * request and held in memory, so after another plugin has written the option it
 * can be stale. The database is the authority.
 *
 * @return array Roles currently stored, or an empty array.
 */
function dahu_role_backup_read_current() {
    $value = get_option( dahu_role_backup_option_name() );
    return is_array( $value ) ? $value : array();
}


/* -------------------------------------------------------------------------
 * Reading and validating a snapshot
 * ---------------------------------------------------------------------- */

/**
 * Loads a snapshot by file name.
 *
 * The name is validated against a regular expression BEFORE any path is built,
 * and basename() is forced: no directory traversal is possible from a URL
 * parameter.
 *
 * @param string $filename Bare file name, no path.
 * @return array|WP_Error Decoded snapshot with file and path added, or WP_Error.
 */
function dahu_role_backup_load( $filename ) {
    $filename = basename( (string) $filename );

    if ( ! dahu_role_backup_parse_filename( $filename ) ) {
        return new WP_Error( 'dahu_role_backup_nom', __( 'Invalid snapshot name.', 'dahu-role-backup' ) );
    }

    $path = dahu_role_backup_dir() . '/' . $filename;

    if ( ! is_readable( $path ) ) {
        return new WP_Error( 'dahu_role_backup_absent', __( 'Snapshot not found: ', 'dahu-role-backup' ) . $filename );
    }

    $data = json_decode( (string) file_get_contents( $path ), true );

    if ( ! is_array( $data ) || empty( $data['roles'] ) || ! is_array( $data['roles'] ) ) {
        return new WP_Error( 'dahu_role_backup_corrompu', __( 'Snapshot unreadable or corrupted: ', 'dahu-role-backup' ) . $filename );
    }

    $data['file'] = $filename;
    $data['path'] = $path;

    return $data;
}

/**
 * BLOCKING check that the snapshot belongs to this installation.
 *
 * Several installations can share one database under distinct table prefixes,
 * as a production site and its staging copy commonly do. Restoring a staging
 * snapshot onto production by mistake would overwrite the real roles with those
 * of a sandbox. This check makes that error impossible rather than merely
 * unlikely.
 *
 * @param array $snapshot Loaded snapshot.
 * @return true|WP_Error True when the snapshot belongs here, WP_Error otherwise.
 */
function dahu_role_backup_check_origin( $snapshot ) {
    global $wpdb;

    $expected_prefix = $wpdb->get_blog_prefix();
    $expected_option = dahu_role_backup_option_name();

    $found_prefix = isset( $snapshot['table_prefix'] ) ? $snapshot['table_prefix'] : '';
    $found_option = isset( $snapshot['option_name'] ) ? $snapshot['option_name'] : '';

    if ( $found_prefix !== $expected_prefix || $found_option !== $expected_option ) {
        return new WP_Error(
            'dahu_role_backup_origine',
            sprintf(
                __( 'This snapshot comes from ANOTHER installation and cannot be restored here. Snapshot: prefix "%s", option "%s". This installation: prefix "%s", option "%s".', 'dahu-role-backup' ),
                $found_prefix,
                $found_option,
                $expected_prefix,
                $expected_option
            )
        );
    }

    return true;
}

/**
 * Difference between a snapshot and the current state.
 *
 * Returns the missing roles (present in the snapshot, absent from the
 * database), the new ones (present in the database, absent from the snapshot)
 * and the ones common to both.
 *
 * @param array $snapshot Loaded snapshot.
 * @return array{manquants: array, nouveaux: array, communs: array}
 */
function dahu_role_backup_diff( $snapshot ) {
    $current = dahu_role_backup_read_current();

    $in_snapshot = dahu_role_backup_slugs( $snapshot['roles'] );
    $in_current  = dahu_role_backup_slugs( $current );

    return array(
        'manquants' => array_values( array_diff( $in_snapshot, $in_current ) ),
        'nouveaux'  => array_values( array_diff( $in_current, $in_snapshot ) ),
        'communs'   => array_values( array_intersect( $in_snapshot, $in_current ) ),
    );
}


/**
 * Why a snapshot was taken, in one sentence, when the reason is a rescue.
 *
 * Returns an empty string for an ordinary daily, change or manual snapshot:
 * there is nothing to say about those, and a screen that explains every row
 * explains nothing.
 *
 * @param string $file Snapshot file name.
 * @return string
 */
function dahu_role_backup_rescue_reason( $file ) {
    $snapshot = dahu_role_backup_load( $file );

    if ( is_wp_error( $snapshot ) || ! is_array( $snapshot ) ) {
        return '';
    }

    $event = isset( $snapshot['evenement'] ) ? (string) $snapshot['evenement'] : '';

    if ( 'copie_perimee' !== $event ) {
        return '';
    }

    $lost = isset( $snapshot['roles_perdus'] ) ? (array) $snapshot['roles_perdus'] : array();

    if ( ! $lost ) {
        return __( 'Taken because a write was about to erase roles that were still stored.', 'dahu-role-backup' );
    }

    return sprintf(
        /* translators: %s: comma-separated list of role slugs. */
        __( 'Taken because a write was about to erase roles that were still stored: %s.', 'dahu-role-backup' ),
        implode( ', ', array_map( 'sanitize_key', $lost ) )
    );
}
