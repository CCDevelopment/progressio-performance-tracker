<?php
/**
 * WP-CLI commands for Progressio Performance Tracker.
 *
 *   wp ppt status                 Configuration + lead delivery status.
 *   wp ppt test-lead [--email=]   Send a test lead to the Leads API.
 *   wp ppt queue [--process|--clear]
 *   wp ppt get <key>              Read a setting.
 *   wp ppt set <key> <value>      Write a setting (runs the same validation as the UI).
 *
 * @package Progressio_Performance_Tracker
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WP_CLI' ) ) {
	return;
}

class PPT_CLI {

	/**
	 * Show configuration and lead delivery status.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table (default) or json.
	 *
	 * @subcommand status
	 */
	public function status( array $args, array $assoc ): void {
		$settings = PPT_Settings::get_instance();
		$leads    = PPT_Leads::get_instance();
		$status   = $leads->get_status();
		$key      = (string) $settings->get( 'leads_api_key' );
		$active   = array_filter( PPT_Leads::detect_form_plugins(), static fn( $p ) => $p['active'] );

		$rows = array(
			array( 'key' => 'version', 'value' => PPT_VERSION ),
			array( 'key' => 'measurement_id', 'value' => (string) $settings->get( 'measurement_id' ) ?: '(none)' ),
			array( 'key' => 'load_gtag', 'value' => (string) $settings->get( 'load_gtag', '1' ) ),
			array( 'key' => 'debug_mode', 'value' => (string) $settings->get( 'debug_mode', '0' ) ),
			array( 'key' => 'form_plugin', 'value' => (string) $settings->get( 'form_plugin', 'auto' ) ),
			array( 'key' => 'form_plugins_active', 'value' => $active ? implode( ', ', array_column( $active, 'label' ) ) : '(none)' ),
			array( 'key' => 'leads_api_key', 'value' => $key ? '••••' . substr( $key, -4 ) . ( $settings->is_constant( 'leads_api_key' ) ? ' (constant)' : '' ) : '(none)' ),
			array( 'key' => 'leads_endpoint', 'value' => (string) $settings->get( 'leads_endpoint' ) ),
			array( 'key' => 'leads_configured', 'value' => $leads->is_configured() ? 'yes' : 'no' ),
			array( 'key' => 'last_sent_at', 'value' => $status['last_sent_at'] ? gmdate( 'c', $status['last_sent_at'] ) : '(never)' ),
			array( 'key' => 'last_lead_id', 'value' => $status['last_lead_id'] ?: '-' ),
			array( 'key' => 'total_sent', 'value' => (string) $status['total_sent'] ),
			array( 'key' => 'total_failed', 'value' => (string) $status['total_failed'] ),
			array( 'key' => 'last_error', 'value' => $status['last_error'] ? gmdate( 'c', $status['last_error_at'] ) . ' ' . $status['last_error'] : '-' ),
			array( 'key' => 'queue_length', 'value' => (string) count( $leads->get_queue() ) ),
			array( 'key' => 'next_retry', 'value' => wp_next_scheduled( PPT_CRON_HOOK ) ? gmdate( 'c', wp_next_scheduled( PPT_CRON_HOOK ) ) : '-' ),
		);

		WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'key', 'value' ) );
	}

	/**
	 * Send a test lead to the Leads API.
	 *
	 * ## OPTIONS
	 *
	 * [--email=<email>]
	 * : Email address to use for the test lead.
	 *
	 * @subcommand test-lead
	 */
	public function test_lead( array $args, array $assoc ): void {
		$result = PPT_Leads::get_instance()->send_test( (string) ( $assoc['email'] ?? '' ) );
		if ( $result['ok'] ) {
			WP_CLI::success( sprintf( 'Test lead accepted (HTTP %d, lead ID %s).', $result['code'], $result['lead_id'] ?: '-' ) );
			return;
		}
		WP_CLI::error( $result['error'] );
	}

	/**
	 * Inspect, process or clear the lead retry queue.
	 *
	 * ## OPTIONS
	 *
	 * [--process]
	 * : Retry every queued lead now, ignoring backoff timers.
	 *
	 * [--clear]
	 * : Discard the queue.
	 *
	 * @subcommand queue
	 */
	public function queue( array $args, array $assoc ): void {
		$leads = PPT_Leads::get_instance();

		if ( isset( $assoc['clear'] ) ) {
			$leads->clear_queue();
			WP_CLI::success( 'Queue cleared.' );
			return;
		}

		if ( isset( $assoc['process'] ) ) {
			$s = $leads->process_queue( true );
			WP_CLI::success( sprintf( '%d sent, %d failed, %d dropped, %d remaining.', $s['sent'], $s['failed'], $s['dropped'], $s['remaining'] ) );
			return;
		}

		$queue = $leads->get_queue();
		if ( ! $queue ) {
			WP_CLI::log( 'Queue is empty.' );
			return;
		}
		$rows = array_map( static fn( $i ) => array(
			'id'         => $i['id'] ?? '',
			'email'      => $i['payload']['email'] ?? '',
			'form'       => $i['payload']['formTitle'] ?? '',
			'attempts'   => $i['attempts'] ?? 0,
			'next_at'    => gmdate( 'c', (int) ( $i['next_at'] ?? 0 ) ),
			'last_error' => $i['last_error'] ?? '',
		), $queue );
		WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'email', 'form', 'attempts', 'next_at', 'last_error' ) );
	}

	/**
	 * Read a setting.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Setting key (e.g. measurement_id, form_plugin, leads_endpoint).
	 *
	 * @subcommand get
	 */
	public function get( array $args ): void {
		$key = (string) $args[0];
		if ( ! array_key_exists( $key, ppt_default_options() ) ) {
			WP_CLI::error( "Unknown setting '{$key}'." );
		}
		$value = PPT_Settings::get_instance()->get( $key );
		if ( 'leads_api_key' === $key && $value ) {
			$value = '••••' . substr( (string) $value, -4 );
		}
		WP_CLI::print_value( $value );
	}

	/**
	 * Write a setting. Validation matches the settings screen.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Setting key.
	 *
	 * <value>
	 * : New value. Use 1/0 for checkboxes.
	 *
	 * @subcommand set
	 */
	public function set( array $args ): void {
		[ $key, $value ] = array( (string) $args[0], (string) ( $args[1] ?? '' ) );
		$defaults = ppt_default_options();
		if ( ! array_key_exists( $key, $defaults ) || 'field_map' === $key ) {
			WP_CLI::error( "Setting '{$key}' cannot be set from the CLI." );
		}

		$settings = PPT_Settings::get_instance();
		if ( $settings->is_constant( $key ) ) {
			WP_CLI::error( "'{$key}' is defined as a constant in wp-config.php." );
		}

		$current = get_option( PPT_OPTION_KEY, array() );
		$current = is_array( $current ) ? $current : array();
		$input   = array_merge( $defaults, $current, array( $key => $value ) );

		// Re-express as the form would post it: checkboxes omitted when off,
		// and the API key only present when it is being changed.
		foreach ( array( 'load_gtag', 'debug_mode', 'track_forms', 'track_scroll', 'track_outbound', 'track_phone', 'track_email', 'track_downloads', 'track_video' ) as $flag ) {
			if ( '1' !== (string) $input[ $flag ] ) {
				unset( $input[ $flag ] );
			}
		}
		if ( 'leads_api_key' !== $key ) {
			unset( $input['leads_api_key'] );
		} elseif ( '' === $value ) {
			$input['leads_api_key_clear'] = '1';
		}

		$clean  = $settings->sanitize_options( $input );
		$errors = $settings->last_errors();
		if ( $errors ) {
			WP_CLI::error( $errors[0] );
		}
		update_option( PPT_OPTION_KEY, $clean );
		WP_CLI::success( "Updated {$key}." );
	}
}

WP_CLI::add_command( 'ppt', 'PPT_CLI' );
