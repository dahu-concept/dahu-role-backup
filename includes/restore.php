<?php
/**
 * Restoring roles from a snapshot.
 *
 * @package DahuRoleBackup
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Restoring
 * ---------------------------------------------------------------------- */

/**
 * Restores roles from a snapshot.
 *
 * ADDITIVE mode (the default): only the roles present in the snapshot and
 * ABSENT from the database are recreated. Nothing is deleted, and a role that
 * is already present is left untouched, even if its capabilities have changed
 * since.
 *
 * That default is not decorative caution. A naive restore that simply put back
 * the array as it stood on a given date would delete every role created since:
 * the rescue tool would reproduce exactly the bug it is meant to repair. Full
 * replacement remains available, but it has to be asked for explicitly.
 *
 * @param string $filename Bare snapshot file name, no path.
 * @param bool   $full     True to replace the whole option instead of adding.
 * @return array|WP_Error Result array, or WP_Error when the snapshot is unusable.
 */
function dahu_role_backup_restore( $filename, $full = false ) {
    $snapshot = dahu_role_backup_load( $filename );

    if ( is_wp_error( $snapshot ) ) {
        return $snapshot;
    }

    $origin = dahu_role_backup_check_origin( $snapshot );

    if ( is_wp_error( $origin ) ) {
        return $origin;
    }

    // THE IN-MEMORY ROLES ARE REFILLED FROM THE TABLE BEFORE ANYTHING IS
    // WRITTEN. WordPress keeps every role in one option and rewrites the
    // WHOLE option from its in-memory copy, so a stale copy does not merely
    // fail to see a role: it erases it. Both branches below write that
    // option — add_role() through the core API, update_option() directly —
    // so both would restore from, and write back, whatever the copy had not
    // heard about. See dahu_role_backup_reload_roles().
    dahu_role_backup_reload_roles();

    // A safety net for the safety net: the current state is frozen before any
    // change is made, so a restore that goes wrong stays reversible.
    $current = dahu_role_backup_read_current();

    if ( ! empty( $current ) ) {
        dahu_role_backup_write( 'manual', $current, array(
            'evenement' => 'avant_restauration',
            'source'    => $snapshot['file'],
        ) );
    }

    $diff = dahu_role_backup_diff( $snapshot );

    if ( $full ) {
        $ok = update_option( dahu_role_backup_option_name(), $snapshot['roles'] );

        // Writing the option directly leaves WP_Roles holding the state from
        // before the restore. The next write of that option in this request —
        // one add_cap() is enough — would rewrite the table from that copy and
        // undo everything just restored.
        dahu_role_backup_reload_roles();

        dahu_role_backup_log( sprintf(
            'FULL RESTORE from %1$s | roles restored: %2$s | roles overwritten: %3$s | %4$s',
            $snapshot['file'],
            implode( ', ', dahu_role_backup_slugs( $snapshot['roles'] ) ),
            empty( $diff['nouveaux'] ) ? '(none)' : implode( ', ', $diff['nouveaux'] ),
            dahu_role_backup_context()
        ) );

        return array(
            'mode'      => 'full',
            'restaures' => dahu_role_backup_slugs( $snapshot['roles'] ),
            'supprimes' => $diff['nouveaux'],
            'ecrit'     => (bool) $ok,
        );
    }

    if ( empty( $diff['manquants'] ) ) {
        return array(
            'mode'      => 'additif',
            'restaures' => array(),
            'echecs'    => array(),
            'message'   => __( 'No role missing: nothing to restore.', 'dahu-role-backup' ),
        );
    }

    $restored = array();
    $failed   = array();

    foreach ( $diff['manquants'] as $slug ) {
        $definition = $snapshot['roles'][ $slug ];

        $name = isset( $definition['name'] ) ? $definition['name'] : $slug;
        $caps = ( isset( $definition['capabilities'] ) && is_array( $definition['capabilities'] ) )
            ? $definition['capabilities']
            : array();

        // add_role() is used rather than writing the option directly: the core
        // API updates the in-memory WP_Roles object at the same time as the
        // database, which avoids an inconsistency for the rest of the current
        // request.
        $result = add_role( $slug, $name, $caps );

        if ( null === $result ) {
            $failed[] = $slug;
        } else {
            $restored[] = $slug;
        }
    }

    dahu_role_backup_log( sprintf(
        'ADDITIVE RESTORE from %1$s | restored: %2$s | failures: %3$s | %4$s',
        $snapshot['file'],
        empty( $restored ) ? '(none)' : implode( ', ', $restored ),
        empty( $failed ) ? '(none)' : implode( ', ', $failed ),
        dahu_role_backup_context()
    ) );

    return array(
        'mode'      => 'additif',
        'restaures' => $restored,
        'echecs'    => $failed,
    );
}

