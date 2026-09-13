<?php
/**
 * Uninstall handler — removes every option, transient and cron event the plugin
 * created. Runs only when the plugin is deleted from the Plugins screen, never
 * on deactivation.
 *
 * @package Progressio_Performance_Tracker
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

function ppt_uninstall_site(): void {
	delete_option( 'ppt_settings' );
	delete_option( 'ppt_lead_status' );
	delete_option( 'ppt_lead_queue' );
	delete_option( 'external_updates-progressio-performance-tracker' ); // PUC state.
	wp_clear_scheduled_hook( 'ppt_process_lead_queue' );
}

if ( is_multisite() ) {
	$ppt_site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
	foreach ( $ppt_site_ids as $ppt_site_id ) {
		switch_to_blog( (int) $ppt_site_id );
		ppt_uninstall_site();
		restore_current_blog();
	}
	unset( $ppt_site_ids, $ppt_site_id );
} else {
	ppt_uninstall_site();
}
