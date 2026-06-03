<?php
/**
 * Plugin Name:       Progressio Performance Tracker
 * Plugin URI:        https://progressiodev.com
 * Description:       Tracks button clicks, form submissions, and traffic attribution data, sending custom events to GA4. Connects traffic source to conversion action for client reporting.
 * Version:           1.0.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Progressio Development
 * Author URI:        https://progressiodev.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       progressio-performance-tracker
 * Domain Path:       /languages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'PPT_VERSION', '1.0.1' );
define( 'PPT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PPT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PPT_PLUGIN_FILE', __FILE__ );
define( 'PPT_OPTION_KEY', 'ppt_settings' );

// Load core includes.
require_once PPT_PLUGIN_DIR . 'includes/class-ppt-settings.php';
require_once PPT_PLUGIN_DIR . 'includes/class-ppt-tracker.php';

/**
 * Initialise the plugin.
 */
function ppt_init(): void {
	PPT_Settings::get_instance();
	PPT_Tracker::get_instance();
}
add_action( 'plugins_loaded', 'ppt_init' );

/**
 * Activation hook — set default options.
 */
function ppt_activate(): void {
	$defaults = array(
		'measurement_id'        => '',
		'load_gtag'             => '1',
		'cta_primary_class'     => 'btn--action',
		'cta_primary_label'     => 'Primary CTA',
		'cta_secondary_class'   => 'btn--secondary',
		'cta_secondary_label'   => 'Secondary CTA',
		'cta_tertiary_class'    => 'btn--tertiary',
		'cta_tertiary_label'    => 'Tertiary CTA',
		'track_forms'           => '1',
		'form_plugin'           => 'auto',
		'debug_mode'            => '0',
		'track_scroll'          => '1',
		'track_outbound'        => '1',
		'track_phone'           => '1',
		'track_email'           => '1',
	);

	if ( ! get_option( PPT_OPTION_KEY ) ) {
		add_option( PPT_OPTION_KEY, $defaults );
	}
}
register_activation_hook( PPT_PLUGIN_FILE, 'ppt_activate' );

/**
 * Deactivation hook.
 */
function ppt_deactivate(): void {
	// Nothing to clean up on deactivation; settings persist intentionally.
}
register_deactivation_hook( PPT_PLUGIN_FILE, 'ppt_deactivate' );
