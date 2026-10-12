<?php
/**
 * WP-CLI commands.
 *
 * @package DahuRoleBackup
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * WP-CLI commands
 * ---------------------------------------------------------------------- */

if ( defined( 'WP_CLI' ) && WP_CLI ) {

    /**
     * Backs up and restores WordPress roles.
     */
    class Dahu_Role_Backup_CLI {

        /**
         * Lists the available snapshots.
         *
         * ## OPTIONS
         *
         * [--format=<format>]
         * : table, csv, json, yaml. Default: table.
         *
         * ## EXAMPLES
         *
         *     wp dahu-role-backup list
         *
         * @param array $args       Positional arguments (unused).
         * @param array $assoc_args Associative arguments.
         * @return void
         */
        public function list( $args, $assoc_args ) {
            $all = dahu_role_backup_list();

            if ( empty( $all ) ) {
                WP_CLI::warning( __( 'No snapshot.', 'dahu-role-backup' ) );
                return;
            }

            $best = dahu_role_backup_best_recent( $all );
            $rows = array();

            foreach ( $all as $meta ) {
                $rows[] = array(
                    'date_utc'  => gmdate( 'Y-m-d H:i:s', $meta['timestamp'] ),
                    'type'      => $meta['type'],
                    'roles'     => $meta['count'],
                    'protected' => ( $best && $best['file'] === $meta['file'] ) ? 'yes' : '',
                    'file'      => $meta['file'],
                );
            }

            $format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

            WP_CLI\Utils\format_items( $format, $rows, array( 'date_utc', 'type', 'roles', 'protected', 'file' ) );
        }

        /**
         * Creates an immediate snapshot.
         *
         * ## EXAMPLES
         *
         *     wp dahu-role-backup snapshot
         *
         * @return void
         */
        public function snapshot() {
            $result = dahu_role_backup_write( 'manual', dahu_role_backup_read_current(), array( 'evenement' => 'wp-cli' ) );

            if ( is_wp_error( $result ) ) {
                WP_CLI::error( $result->get_error_message() );
            }

            if ( ! empty( $result['skipped'] ) ) {
                WP_CLI::success( sprintf(
                    /* translators: %s: file name of the identical snapshot already on disk. */
                    __( 'No change since %s: nothing to write.', 'dahu-role-backup' ),
                    $result['file']
                ) );
                return;
            }

            WP_CLI::success( sprintf(
                /* translators: 1: snapshot file name, 2: number of roles it holds. */
                _n( 'Snapshot created: %1$s (%2$d role).', 'Snapshot created: %1$s (%2$d roles).', $result['count'], 'dahu-role-backup' ),
                $result['file'],
                $result['count']
            ) );
        }

        /**
         * Shows the difference between a snapshot and the current state.
         *
         * ## OPTIONS
         *
         * <file>
         * : Snapshot file name.
         *
         * ## EXAMPLES
         *
         *     wp dahu-role-backup diff dahu-roles-daily-20260907-031500-r11-a1b2c3d4.json
         *
         * @param array $args Positional arguments; the snapshot file name.
         * @return void
         */
        public function diff( $args ) {
            $snapshot = dahu_role_backup_load( $args[0] );

            if ( is_wp_error( $snapshot ) ) {
                WP_CLI::error( $snapshot->get_error_message() );
            }

            $diff = dahu_role_backup_diff( $snapshot );

            $none = __( '(none)', 'dahu-role-backup' );

            WP_CLI::log( sprintf(
                /* translators: %s: comma-separated role slugs, or the "(none)" placeholder. */
                __( 'Roles missing (would be recreated): %s', 'dahu-role-backup' ),
                empty( $diff['manquants'] ) ? $none : implode( ', ', $diff['manquants'] )
            ) );
            WP_CLI::log( sprintf(
                /* translators: %s: comma-separated role slugs, or the "(none)" placeholder. */
                __( 'Roles missing from the snapshot: %s', 'dahu-role-backup' ),
                empty( $diff['nouveaux'] ) ? $none : implode( ', ', $diff['nouveaux'] )
            ) );
            WP_CLI::log( sprintf(
                /* translators: %s: comma-separated role slugs, or the "(none)" placeholder. */
                __( 'Roles common to both: %s', 'dahu-role-backup' ),
                empty( $diff['communs'] ) ? $none : implode( ', ', $diff['communs'] )
            ) );
        }

        /**
         * Restores roles from a snapshot.
         *
         * By default the restore is ADDITIVE: it only recreates the roles that
         * are absent and never deletes anything.
         *
         * ## OPTIONS
         *
         * <file>
         * : Snapshot file name.
         *
         * [--full]
         * : Replace the roles option in full. Every role absent from the
         * snapshot will be deleted.
         *
         * [--yes]
         * : Do not ask for confirmation (required with --full when scripted).
         *
         * ## EXAMPLES
         *
         *     wp dahu-role-backup restore dahu-roles-change-20260907-031500-r11-a1b2c3d4.json
         *     wp dahu-role-backup restore <file> --full
         *
         * @param array $args       Positional arguments; the snapshot file name.
         * @param array $assoc_args Associative arguments (full, yes).
         * @return void
         */
        public function restore( $args, $assoc_args ) {
            $file = $args[0];
            $full = isset( $assoc_args['full'] );

            $snapshot = dahu_role_backup_load( $file );

            if ( is_wp_error( $snapshot ) ) {
                WP_CLI::error( $snapshot->get_error_message() );
            }

            $origin = dahu_role_backup_check_origin( $snapshot );

            if ( is_wp_error( $origin ) ) {
                WP_CLI::error( $origin->get_error_message() );
            }

            $diff = dahu_role_backup_diff( $snapshot );

            $none = __( '(none)', 'dahu-role-backup' );

            if ( $full ) {
                WP_CLI::warning( sprintf(
                    /* translators: %s: comma-separated role slugs, or the "(none)" placeholder. */
                    __( 'FULL mode. Roles that will be DELETED: %s', 'dahu-role-backup' ),
                    empty( $diff['nouveaux'] ) ? $none : implode( ', ', $diff['nouveaux'] )
                ) );
                WP_CLI::confirm( __( 'Replace every role with the snapshot?', 'dahu-role-backup' ), $assoc_args );
            } else {
                WP_CLI::log( sprintf(
                    /* translators: %s: comma-separated role slugs, or the "(none)" placeholder. */
                    __( 'Additive mode. Roles to recreate: %s', 'dahu-role-backup' ),
                    empty( $diff['manquants'] ) ? $none : implode( ', ', $diff['manquants'] )
                ) );
            }

            $result = dahu_role_backup_restore( $file, $full );

            if ( is_wp_error( $result ) ) {
                WP_CLI::error( $result->get_error_message() );
            }

            if ( empty( $result['restaures'] ) ) {
                WP_CLI::success( isset( $result['message'] ) ? $result['message'] : __( 'Nothing to restore.', 'dahu-role-backup' ) );
                return;
            }

            if ( ! empty( $result['echecs'] ) ) {
                WP_CLI::warning( sprintf(
                    /* translators: %s: comma-separated slugs of the roles that could not be recreated. */
                    __( 'Failures: %s', 'dahu-role-backup' ),
                    implode( ', ', $result['echecs'] )
                ) );
            }

            WP_CLI::success( sprintf(
                /* translators: %s: comma-separated slugs of the roles that were recreated. */
                __( 'Roles restored: %s', 'dahu-role-backup' ),
                implode( ', ', $result['restaures'] )
            ) );
        }
    }

    WP_CLI::add_command( 'dahu-role-backup', 'Dahu_Role_Backup_CLI' );
}
