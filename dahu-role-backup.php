<?php
/**
 * Plugin Name:       Dahu Role Backup
 * Plugin URI:        https://dahu-concept.fr
 * Description:       A safety net for WordPress roles and capabilities: timestamped snapshots taken daily and whenever they change, a readable diff, and an additive restore from the admin screen or WP-CLI.
 * Version:           2.0.0
 * Author:            Hugo Vial-Jaime
 * Author URI:        mailto:contact@dahu-concept.fr
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       dahu-role-backup
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 *
 * @package DahuRoleBackup
 */

defined( 'ABSPATH' ) || exit;

define( 'DAHU_ROLE_BACKUP_VERSION', '2.0.0' );
define( 'DAHU_ROLE_BACKUP_FILE', __FILE__ );
define( 'DAHU_ROLE_BACKUP_PATH', plugin_dir_path( __FILE__ ) );
define( 'DAHU_ROLE_BACKUP_URL', plugin_dir_url( __FILE__ ) );
define( 'DAHU_ROLE_BACKUP_SLUG', 'dahu-role-backup' );

/**
 * How many snapshots the list shows before offering to show the rest.
 *
 * The retention limit is thirty daily plus thirty on change, so the list can
 * reach sixty rows of near-identical file names. The snapshot somebody wants is
 * almost always one of the most recent few.
 */
define( 'DAHU_ROLE_BACKUP_LIST_LIMIT', 20 );

/** Cron hook taking the daily snapshot. */
define( 'DAHU_ROLE_BACKUP_CRON', 'dahu_role_backup_daily' );

/**
 * Retention.
 *
 * Snapshots are capped per kind, but the best recent one is protected on top
 * of that (see dahu_role_backup_prune). Without that exception, a run of
 * repeated disappearances would rotate the retention until no copy held the
 * lost roles any more: the safety net would quietly empty itself at exactly
 * the moment it was needed.
 */
define( 'DAHU_ROLE_BACKUP_KEEP_DAILY', 30 );
define( 'DAHU_ROLE_BACKUP_KEEP_CHANGE', 30 );
define( 'DAHU_ROLE_BACKUP_BEST_WINDOW_DAYS', 90 );

/** Beyond this, the last snapshot is considered too old and a notice is shown. */
define( 'DAHU_ROLE_BACKUP_STALE_HOURS', 48 );

/* -------------------------------------------------------------------------
 * Translations
 * ---------------------------------------------------------------------- */

add_action(
	'init',
	static function () {
		load_plugin_textdomain(
			'dahu-role-backup',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
	}
);

/* -------------------------------------------------------------------------
 * Modules
 * ---------------------------------------------------------------------- */

require_once DAHU_ROLE_BACKUP_PATH . 'includes/storage.php';
require_once DAHU_ROLE_BACKUP_PATH . 'includes/snapshot.php';
require_once DAHU_ROLE_BACKUP_PATH . 'includes/restore.php';
require_once DAHU_ROLE_BACKUP_PATH . 'includes/schedule.php';

if ( is_admin() ) {
	require_once DAHU_ROLE_BACKUP_PATH . 'includes/admin.php';
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once DAHU_ROLE_BACKUP_PATH . 'includes/cli.php';
}

/* -------------------------------------------------------------------------
 * Lifecycle
 * ---------------------------------------------------------------------- */

register_activation_hook( __FILE__, 'dahu_role_backup_activate' );
register_deactivation_hook( __FILE__, 'dahu_role_backup_deactivate' );

/**
 * URL of the plugin's admin screen.
 *
 * Centralised so no link is ever built by hand.
 *
 * @param array<string, string> $args Extra query arguments.
 * @return string
 */
function dahu_role_backup_url( $args = array() ) {
	return add_query_arg(
		array_merge( array( 'page' => DAHU_ROLE_BACKUP_SLUG ), $args ),
		admin_url( 'tools.php' )
	);
}

/* -------------------------------------------------------------------------
 * Plugins screen
 * ---------------------------------------------------------------------- */

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( $links ) {
		$page = sprintf(
			'<a href="%s">%s</a>',
			esc_url( dahu_role_backup_url() ),
			esc_html__( 'Snapshots', 'dahu-role-backup' )
		);
		array_unshift( $links, $page );
		return $links;
	}
);

add_filter(
	'plugin_row_meta',
	static function ( $links, $file ) {

		if ( plugin_basename( __FILE__ ) !== $file ) {
			return $links;
		}

		$header = get_file_data( __FILE__, array( 'Version' => 'Version' ) );

		return array(
			sprintf(
				/* translators: %s: plugin version number. */
				esc_html__( 'Version %s', 'dahu-role-backup' ),
				esc_html( $header['Version'] )
			),
			sprintf(
				'<a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( 'https://dahu-concept.fr' ),
				esc_html__( 'By Hugo Vial-Jaime — Dahu-Concept', 'dahu-role-backup' )
			),
			sprintf(
				'<a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( 'https://dahu-concept.fr' ),
				esc_html__( 'Plugin website', 'dahu-role-backup' )
			),
		);
	},
	10,
	2
);
