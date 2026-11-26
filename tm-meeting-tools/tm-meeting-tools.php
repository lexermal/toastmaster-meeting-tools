<?php
/**
 * Plugin Name:       TM Meeting Tools
 * Description:       Toastmasters meeting agenda, timekeeper, Ah-Counter and feedback tools. Every action is also exposed through the WordPress Abilities API.
 * Version:           1.1.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Malmo Toastmasters
 * License:           GPL-2.0-or-later
 * Text Domain:       tm-meeting-tools
 * Domain Path:       /languages
 *
 * @package TM_Meeting_Tools
 */

defined( 'ABSPATH' ) || exit;

define( 'TMMT_VERSION', '1.1.0' );
define( 'TMMT_DB_VERSION', '6' );
define( 'TMMT_FILE', __FILE__ );
define( 'TMMT_DIR', plugin_dir_path( __FILE__ ) );
define( 'TMMT_URL', plugin_dir_url( __FILE__ ) );

require_once TMMT_DIR . 'includes/helpers.php';
require_once TMMT_DIR . 'includes/class-tmmt-install.php';
require_once TMMT_DIR . 'includes/class-tmmt-auth.php';
require_once TMMT_DIR . 'includes/class-tmmt-roles.php';
require_once TMMT_DIR . 'includes/class-tmmt-members.php';
require_once TMMT_DIR . 'includes/class-tmmt-meetings.php';
require_once TMMT_DIR . 'includes/class-tmmt-results.php';
require_once TMMT_DIR . 'includes/class-tmmt-schemas.php';
require_once TMMT_DIR . 'includes/class-tmmt-abilities.php';
require_once TMMT_DIR . 'includes/class-tmmt-frontend.php';

if ( is_admin() ) {
	require_once TMMT_DIR . 'includes/class-tmmt-admin.php';
	require_once TMMT_DIR . 'includes/class-tmmt-admin-meetings.php';
	require_once TMMT_DIR . 'includes/class-tmmt-admin-reports.php';
	require_once TMMT_DIR . 'includes/class-tmmt-admin-members.php';
}

register_activation_hook( __FILE__, array( 'TMMT_Install', 'activate' ) );

add_action( 'plugins_loaded', array( 'TMMT_Install', 'maybe_upgrade' ) );
add_action( 'init', 'tmmt_load_textdomain' );
add_action( 'init', array( 'TMMT_Meetings', 'complete_finished' ) );
add_action( 'init', array( 'TMMT_Results', 'purge_old_feedback' ) );
add_action( 'init', array( 'TMMT_Members', 'prune_inactive' ) );
TMMT_Abilities::init();
TMMT_Frontend::init();
if ( is_admin() ) {
	TMMT_Admin::init();
	TMMT_Admin_Meetings::init();
	TMMT_Admin_Reports::init();
	TMMT_Admin_Members::init();
}

/**
 * Loads translations.
 */
function tmmt_load_textdomain() {
	load_plugin_textdomain( 'tm-meeting-tools', false, dirname( plugin_basename( TMMT_FILE ) ) . '/languages' );
}
