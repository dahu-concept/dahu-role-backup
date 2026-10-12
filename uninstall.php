<?php
/**
 * Uninstall routine.
 *
 * The snapshots are NOT deleted. They are the whole point of the plugin: a
 * safety net kept precisely for the day something goes wrong with the roles,
 * and uninstalling is often a step in reinstalling or in switching tools. A
 * routine that wiped them would be most destructive exactly when the site had
 * a role problem to fix.
 *
 * The directory is left in place and reported, so the site owner removes it
 * knowingly. Only the plugin's own options and its scheduled event go.
 *
 * @package DahuRoleBackup
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site.
 *
 * @return void
 */
function dahu_role_backup_uninstall_site() {

	$timestamp = wp_next_scheduled( 'dahu_role_backup_daily' );

	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'dahu_role_backup_daily' );
	}

	wp_clear_scheduled_hook( 'dahu_role_backup_daily' );

	delete_option( 'dahu_role_backup_last' );
}

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		dahu_role_backup_uninstall_site();
		restore_current_blog();
	}
} else {
	dahu_role_backup_uninstall_site();
}
