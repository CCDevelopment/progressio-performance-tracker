<?php
/**
 * Plugin Name:       Progressio Performance Tracker
 * Plugin URI:        https://progressiodev.com
 * Description:       Tracks button clicks, form submissions, and traffic attribution data, sending custom events to GA4. Connects traffic source to conversion action for client reporting.
 * Version:           1.5.0
 * Requires at least: 5.9
 * Requires PHP:      8.0
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
define( 'PPT_VERSION', '1.5.0' );
define( 'PPT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PPT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PPT_PLUGIN_FILE', __FILE__ );
define( 'PPT_OPTION_KEY', 'ppt_settings' );
define( 'PPT_STATUS_KEY', 'ppt_lead_status' );
define( 'PPT_QUEUE_KEY', 'ppt_lead_queue' );
define( 'PPT_CRON_HOOK', 'ppt_process_lead_queue' );
define( 'PPT_DEFAULT_ENDPOINT', 'https://dashboard.progressiodev.com/api/leads' );

// Load core includes.
require_once PPT_PLUGIN_DIR . 'includes/class-ppt-settings.php';
require_once PPT_PLUGIN_DIR . 'includes/class-ppt-attribution.php';
require_once PPT_PLUGIN_DIR . 'includes/class-ppt-leads.php';
require_once PPT_PLUGIN_DIR . 'includes/class-ppt-tracker.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once PPT_PLUGIN_DIR . 'includes/class-ppt-cli.php';
}

// Auto-updates via GitHub releases.
$ppt_puc = PPT_PLUGIN_DIR . 'includes/plugin-update-checker/plugin-update-checker.php';
if ( file_exists( $ppt_puc ) ) {
	try {
		require_once $ppt_puc;
		$ppt_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/CCDevelopment/progressio-performance-tracker/',
			__FILE__,
			'progressio-performance-tracker'
		);
		$ppt_update_checker->setBranch( 'master' );
	} catch ( \Throwable $e ) {
		// Update checker failed — plugin continues to work normally.
	}
	unset( $ppt_puc, $ppt_update_checker );
}

/**
 * Initialise the plugin.
 */
function ppt_init(): void {
	PPT_Settings::get_instance();
	PPT_Leads::get_instance();
	PPT_Tracker::get_instance();
}
add_action( 'plugins_loaded', 'ppt_init' );

/**
 * Default option values. Shared by activation and the settings sanitizer.
 */
function ppt_default_options(): array {
	return array(
		'measurement_id'      => '',
		'load_gtag'           => '1',
		'debug_mode'          => '0',
		'cta_primary_class'   => 'btn--action',
		'cta_primary_label'   => 'Primary CTA',
		'cta_secondary_class' => 'btn--secondary',
		'cta_secondary_label' => 'Secondary CTA',
		'cta_tertiary_class'  => 'btn--tertiary',
		'cta_tertiary_label'  => 'Tertiary CTA',
		'track_forms'         => '1',
		'form_plugin'         => 'auto',
		'track_scroll'        => '1',
		'track_outbound'      => '1',
		'track_phone'         => '1',
		'track_email'         => '1',
		'track_downloads'     => '1',
		'track_video'         => '1',
		'attribution_days'    => 90,
		'leads_api_key'       => '',
		'leads_endpoint'      => PPT_DEFAULT_ENDPOINT,
		'field_map'           => array(),
	);
}

/**
 * Activation hook — set default options.
 */
function ppt_activate(): void {
	$existing = get_option( PPT_OPTION_KEY );
	if ( ! is_array( $existing ) ) {
		add_option( PPT_OPTION_KEY, ppt_default_options() );
	} else {
		// Upgrade path: add any keys introduced since the option was first written.
		update_option( PPT_OPTION_KEY, array_merge( ppt_default_options(), $existing ) );
	}
}
register_activation_hook( PPT_PLUGIN_FILE, 'ppt_activate' );

/**
 * Deactivation hook — clear the lead retry cron. The queue itself is kept so
 * pending leads are retried if the plugin is reactivated.
 */
function ppt_deactivate(): void {
	wp_clear_scheduled_hook( PPT_CRON_HOOK );
}
register_deactivation_hook( PPT_PLUGIN_FILE, 'ppt_deactivate' );
