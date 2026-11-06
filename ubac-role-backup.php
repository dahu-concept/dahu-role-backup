<?php
/**
 * Plugin Name:       Ubac Role Backup
 * Description:       A safety net for WordPress roles and capabilities: timestamped snapshots taken daily and whenever they change, a readable diff, and an additive restore from the admin screen or WP-CLI.
 * Version:           2.3.1
 * Author:            Hugo Vial-Jaime
 * Author URI:        https://dahu-concept.fr
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       ubac-role-backup
 * Domain Path:       /languages
 * Requires at least: 6.2
 * Requires PHP:      7.4
 *
 * @package UbacRoleBackup
 */

defined( 'ABSPATH' ) || exit;

define( 'UBAC_ROLE_BACKUP_VERSION', '2.3.1' );
define( 'UBAC_ROLE_BACKUP_FILE', __FILE__ );
define( 'UBAC_ROLE_BACKUP_PATH', plugin_dir_path( __FILE__ ) );
define( 'UBAC_ROLE_BACKUP_URL', plugin_dir_url( __FILE__ ) );
define( 'UBAC_ROLE_BACKUP_SLUG', 'ubac-role-backup' );

/**
 * How many snapshots the list shows before offering to show the rest.
 *
 * The retention limit is thirty daily plus thirty on change, so the list can
 * reach sixty rows of near-identical file names. The snapshot somebody wants is
 * almost always one of the most recent few.
 */
define( 'UBAC_ROLE_BACKUP_LIST_LIMIT', 20 );

/** Cron hook taking the daily snapshot. */
define( 'UBAC_ROLE_BACKUP_CRON', 'ubac_role_backup_daily' );

/**
 * Retention.
 *
 * Snapshots are capped per kind, but the best recent one is protected on top
 * of that (see ubac_role_backup_prune). Without that exception, a run of
 * repeated disappearances would rotate the retention until no copy held the
 * lost roles any more: the safety net would quietly empty itself at exactly
 * the moment it was needed.
 */
define( 'UBAC_ROLE_BACKUP_KEEP_DAILY', 30 );
define( 'UBAC_ROLE_BACKUP_KEEP_CHANGE', 30 );
define( 'UBAC_ROLE_BACKUP_BEST_WINDOW_DAYS', 90 );

/** Beyond this, the last snapshot is considered too old and a notice is shown. */
define( 'UBAC_ROLE_BACKUP_STALE_HOURS', 48 );

/* -------------------------------------------------------------------------
 * Translations
 *
 * There is deliberately no load_plugin_textdomain() call here. Since
 * WordPress 4.6 the translations of a plugin hosted on WordPress.org are
 * loaded on demand, and the directory's review asks that the call be removed
 * rather than kept: this plugin requires 6.0, so there is no older WordPress
 * to cover. Translations come from translate.wordpress.org.
 * ---------------------------------------------------------------------- */

/* -------------------------------------------------------------------------
 * Modules
 * ---------------------------------------------------------------------- */

require_once UBAC_ROLE_BACKUP_PATH . 'includes/storage.php';
require_once UBAC_ROLE_BACKUP_PATH . 'includes/snapshot.php';
require_once UBAC_ROLE_BACKUP_PATH . 'includes/restore.php';
require_once UBAC_ROLE_BACKUP_PATH . 'includes/schedule.php';

if ( is_admin() ) {
	require_once UBAC_ROLE_BACKUP_PATH . 'includes/admin.php';
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once UBAC_ROLE_BACKUP_PATH . 'includes/cli.php';
}

/* -------------------------------------------------------------------------
 * Lifecycle
 * ---------------------------------------------------------------------- */

register_activation_hook( __FILE__, 'ubac_role_backup_activate' );
register_deactivation_hook( __FILE__, 'ubac_role_backup_deactivate' );

/**
 * URL of the plugin's admin screen.
 *
 * Centralised so no link is ever built by hand.
 *
 * @param array<string, string> $args Extra query arguments.
 * @return string
 */
function ubac_role_backup_url( $args = array() ) {
	return add_query_arg(
		array_merge( array( 'page' => UBAC_ROLE_BACKUP_SLUG ), $args ),
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
			esc_url( ubac_role_backup_url() ),
			esc_html__( 'Snapshots', 'ubac-role-backup' )
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
				esc_html__( 'Version %s', 'ubac-role-backup' ),
				esc_html( $header['Version'] )
			),
			sprintf(
				'<a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( 'https://dahu-concept.fr' ),
				esc_html__( 'By Hugo Vial-Jaime — Dahu-Concept', 'ubac-role-backup' )
			),
			sprintf(
				'<a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( 'https://dahu-concept.fr' ),
				esc_html__( 'Plugin website', 'ubac-role-backup' )
			),
		);
	},
	10,
	2
);

/*
 * The welcome screen, shown once just after activation.
 *
 * Registered here rather than inside the admin include, because the
 * activation flag has to be set whatever else is loaded.
 */
require_once UBAC_ROLE_BACKUP_PATH . 'includes/ubac-welcome.php';

/*
 * The flag, not a redirect: an activation hook that redirects breaks bulk
 * activation and WP-CLI, which activate several plugins in one request. The
 * welcome screen reads the flag on the next page load, where a redirect is
 * safe, and clears it when several plugins were activated together.
 */
register_activation_hook(
	__FILE__,
	static function () {
		Ubac_Welcome::mark_activated( 'ubac-role-backup' );
	}
);

add_action(
	'init',
	static function () {
		Ubac_Welcome::register(
			array(
				'slug'       => 'ubac-role-backup',
				'name'       => __( 'Role snapshots', 'ubac-role-backup' ),
				'tagline'    => __( 'A copy of every role, taken before anything can lose one.', 'ubac-role-backup' ),
				'file'       => UBAC_ROLE_BACKUP_FILE,
				'version'    => UBAC_ROLE_BACKUP_VERSION,
				'textdomain' => 'ubac-role-backup',
				'mark'       => array(
					'view'  => '0 0 112 112',
					'paths' => array(
						'M56 6 L102 22 L102 60 Q102 92 56 108 Q10 92 10 60 L10 22 Z',
						'M74 64 A18 18 0 1 1 56 46',
						'M47 37 L56 46 L47 55',
					),
				),
				/*
				 * The tour, on the plugin's own screen. Three stops: what it
				 * already did on its own, the button somebody might need in a
				 * hurry, and the status table that shows it is running. A fourth stop would be a
				 * manual.
				 */
				'tour_screen' => 'tools_page_ubac-role-backup',
				'tour'        => array(
					array(
						'selector' => '.ubac-ui-head__title',
						'title'    => __( 'It is already running', 'ubac-role-backup' ),
						'text'     => __( 'A first snapshot was taken when you activated the plugin. Nothing here needs setting up.', 'ubac-role-backup' ),
					),
					array(
						'selector' => '.ubac-rb-form button',
						'title'    => __( 'Take one now', 'ubac-role-backup' ),
						'text'     => __( 'Before you change roles by hand, or install something that might.', 'ubac-role-backup' ),
					),
					array(
						'selector' => '.ubac-rb-table',
						'title'    => __( 'Check it is running', 'ubac-role-backup' ),
						'text'     => __( 'The last snapshot, the next daily run and the roles stored today, at a glance.', 'ubac-role-backup' ),
					),
				),
				/*
				 * The welcome screen's words come from here: the shared file is
				 * copied byte for byte across the whole family, so it can carry
				 * no text domain of its own.
				 */
				'strings'    => array(
					'next'   => __( 'Next', 'ubac-role-backup' ),
					'done'   => __( 'Done', 'ubac-role-backup' ),
					'skip'   => __( 'Skip', 'ubac-role-backup' ),
					'of'     => __( 'of', 'ubac-role-backup' ),
					'denied' => __( 'You are not allowed to view this page.', 'ubac-role-backup' ),
				),
				'points'     => array(
				array(
					'title' => __( 'It watches', 'ubac-role-backup' ),
					'text'  => __( 'Every write to the roles option is read against the database before it lands.', 'ubac-role-backup' ),
				),
				array(
					'title' => __( 'It copies', 'ubac-role-backup' ),
					'text'  => __( 'A snapshot is kept daily, and again whenever the roles change.', 'ubac-role-backup' ),
				),
				array(
					'title' => __( 'It restores', 'ubac-role-backup' ),
					'text'  => __( 'Put back only what went missing, or everything, from the screen.', 'ubac-role-backup' ),
				),
				),
			)
		);
	}
);
