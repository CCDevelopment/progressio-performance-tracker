<?php
/**
 * Settings page for Progressio Performance Tracker.
 *
 * @package Progressio_Performance_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PPT_Settings
 *
 * Registers the admin settings page, validates and saves options, and renders
 * the site-health panel. Secrets can be supplied as constants in wp-config.php
 * (PPT_LEADS_API_KEY, PPT_LEADS_ENDPOINT, PPT_MEASUREMENT_ID); a constant
 * always wins over the stored option.
 */
class PPT_Settings {

	private static ?PPT_Settings $instance = null;

	/** @var array Cached, filtered settings. */
	private array $options = array();

	/** @var string[] Validation messages from the most recent sanitize_options() call. */
	private array $errors = array();

	private const PAGE_SLUG  = 'progressio-performance-tracker';
	private const NONCE      = 'ppt_admin';
	private const FORM_PLUGINS = array( 'auto', 'wsform', 'gravityforms', 'wpforms', 'cf7', 'fluentforms', 'formidable', 'ninja', 'elementor', 'generic' );

	/** Option key → wp-config constant that overrides it. */
	private const CONSTANTS = array(
		'leads_api_key'  => 'PPT_LEADS_API_KEY',
		'leads_endpoint' => 'PPT_LEADS_ENDPOINT',
		'measurement_id' => 'PPT_MEASUREMENT_ID',
	);

	public static function get_instance(): PPT_Settings {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->load_options();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_ppt_test_lead', array( $this, 'ajax_test_lead' ) );
		add_action( 'wp_ajax_ppt_process_queue', array( $this, 'ajax_process_queue' ) );
	}

	private function load_options(): void {
		$stored = get_option( PPT_OPTION_KEY, array() );
		$stored = is_array( $stored ) ? $stored : array();

		/**
		 * Filter the plugin settings after load. Lets a site-specific mu-plugin
		 * supply values without storing them in the database.
		 *
		 * @param array $options
		 */
		$this->options = (array) apply_filters( 'ppt_settings', array_merge( ppt_default_options(), $stored ) );
	}

	/**
	 * Return a single option value. Constants override stored values.
	 *
	 * @param string $key     Option key.
	 * @param mixed  $default Fallback value.
	 * @return mixed
	 */
	public function get( string $key, $default = '' ) {
		if ( isset( self::CONSTANTS[ $key ] ) && defined( self::CONSTANTS[ $key ] ) ) {
			return constant( self::CONSTANTS[ $key ] );
		}
		return $this->options[ $key ] ?? $default;
	}

	/**
	 * Whether a key's value comes from a wp-config constant.
	 */
	public function is_constant( string $key ): bool {
		return isset( self::CONSTANTS[ $key ] ) && defined( self::CONSTANTS[ $key ] );
	}

	/* =========================================================================
	 * VALIDATION (shared with PPT_Leads and WP-CLI)
	 * ======================================================================= */

	public static function is_valid_measurement_id( string $id ): bool {
		return (bool) preg_match( '/^G-[A-Z0-9]{4,20}$/', $id );
	}

	/**
	 * Hosts the Leads endpoint may point at. Extend via the
	 * `ppt_allowed_lead_hosts` filter for staging environments.
	 */
	public static function allowed_endpoint_hosts(): array {
		$hosts = (array) apply_filters( 'ppt_allowed_lead_hosts', array( 'dashboard.progressiodev.com' ) );
		return array_map( 'strtolower', array_filter( array_map( 'strval', $hosts ) ) );
	}

	/**
	 * The endpoint must be HTTPS, pass WordPress' unsafe-URL checks, and sit on
	 * an allow-listed host. An endpoint supplied via the PPT_LEADS_ENDPOINT
	 * constant only needs to be a valid HTTPS URL — wp-config.php is code, not
	 * user input.
	 */
	public static function is_valid_endpoint( string $url ): bool {
		$url = trim( $url );
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return false;
		}
		if ( 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
			return false;
		}
		if ( defined( 'PPT_LEADS_ENDPOINT' ) && $url === PPT_LEADS_ENDPOINT ) {
			return true;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return in_array( $host, self::allowed_endpoint_hosts(), true );
	}

	/* =========================================================================
	 * ADMIN MENU + SETTINGS API
	 * ======================================================================= */

	public function register_menu(): void {
		add_menu_page(
			esc_html__( 'Performance Tracker', 'progressio-performance-tracker' ),
			esc_html__( 'Perf Tracker', 'progressio-performance-tracker' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' ),
			'dashicons-chart-line',
			80
		);
	}

	public function register_settings(): void {
		register_setting(
			'ppt_settings_group',
			PPT_OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_options' ),
				'default'           => ppt_default_options(),
			)
		);

		$page = 'ppt_settings_page';
		$td   = 'progressio-performance-tracker';

		// ── GA4 ──────────────────────────────────────────────────────────────
		add_settings_section( 'ppt_section_ga4', __( 'GA4 Connection', $td ), array( $this, 'section_ga4_cb' ), $page );
		add_settings_field( 'measurement_id', __( 'Measurement ID', $td ), array( $this, 'field_measurement_id' ), $page, 'ppt_section_ga4' );
		add_settings_field( 'load_gtag', __( 'Load gtag.js', $td ), array( $this, 'field_checkbox' ), $page, 'ppt_section_ga4', array( 'key' => 'load_gtag', 'label' => __( 'Load the gtag.js library from this plugin', $td ), 'desc' => __( 'Uncheck if Site Kit or another plugin already loads gtag.js.', $td ) ) );
		add_settings_field( 'debug_mode', __( 'Debug Mode', $td ), array( $this, 'field_checkbox' ), $page, 'ppt_section_ga4', array( 'key' => 'debug_mode', 'label' => __( 'Enable debug mode (logs events to browser console and PHP error log)', $td ), 'desc' => __( 'Disable on live client sites. Uses the GA4 debug_mode parameter so events appear in DebugView.', $td ) ) );

		// ── CTA ──────────────────────────────────────────────────────────────
		add_settings_section( 'ppt_section_cta', __( 'CTA Button Tracking', $td ), array( $this, 'section_cta_cb' ), $page );
		foreach ( array( 'primary', 'secondary', 'tertiary' ) as $tier ) {
			/* translators: %s: tier name */
			add_settings_field( "cta_{$tier}", sprintf( __( '%s CTA', $td ), ucfirst( $tier ) ), array( $this, 'field_cta_tier' ), $page, 'ppt_section_cta', array( 'tier' => $tier ) );
		}

		// ── Forms ────────────────────────────────────────────────────────────
		add_settings_section( 'ppt_section_forms', __( 'Form Submission Tracking', $td ), array( $this, 'section_forms_cb' ), $page );
		add_settings_field( 'track_forms', __( 'Enable Form Tracking', $td ), array( $this, 'field_checkbox' ), $page, 'ppt_section_forms', array( 'key' => 'track_forms', 'label' => __( 'Track form submissions as GA4 events', $td ) ) );
		add_settings_field( 'form_plugin', __( 'Form Plugin', $td ), array( $this, 'field_form_plugin' ), $page, 'ppt_section_forms' );

		// ── Additional tracking ──────────────────────────────────────────────
		add_settings_section( 'ppt_section_extra', __( 'Additional Tracking', $td ), array( $this, 'section_extra_cb' ), $page );
		add_settings_field( 'track_scroll', __( 'Scroll Depth', $td ), array( $this, 'field_checkbox' ), $page, 'ppt_section_extra', array( 'key' => 'track_scroll', 'label' => __( 'Fire events at 25%, 50%, 75%, and 100% scroll depth', $td ) ) );
		add_settings_field( 'track_outbound', __( 'Outbound Link Clicks', $td ), array( $this, 'field_checkbox' ), $page, 'ppt_section_extra', array( 'key' => 'track_outbound', 'label' => __( 'Track clicks on external links', $td ) ) );
		add_settings_field( 'track_phone', __( 'Phone Number Clicks', $td ), array( $this, 'field_checkbox' ), $page, 'ppt_section_extra', array( 'key' => 'track_phone', 'label' => __( 'Track tel: link clicks', $td ) ) );
		add_settings_field( 'track_email', __( 'Email Address Clicks', $td ), array( $this, 'field_checkbox' ), $page, 'ppt_section_extra', array( 'key' => 'track_email', 'label' => __( 'Track mailto: link clicks', $td ) ) );
		add_settings_field( 'track_downloads', __( 'File Downloads', $td ), array( $this, 'field_checkbox' ), $page, 'ppt_section_extra', array( 'key' => 'track_downloads', 'label' => __( 'Track clicks on PDF, document, spreadsheet, archive and media file links', $td ) ) );
		add_settings_field( 'track_video', __( 'Video Engagement', $td ), array( $this, 'field_checkbox' ), $page, 'ppt_section_extra', array( 'key' => 'track_video', 'label' => __( 'Track start / 25 / 50 / 75 / complete for YouTube, Vimeo and HTML5 video', $td ), 'desc' => __( 'YouTube embeds are switched to JS-API mode so they can report playback.', $td ) ) );

		// ── Attribution ──────────────────────────────────────────────────────
		add_settings_section( 'ppt_section_attr', __( 'Attribution', $td ), array( $this, 'section_attr_cb' ), $page );
		add_settings_field( 'attribution_days', __( 'First-touch window (days)', $td ), array( $this, 'field_attribution_days' ), $page, 'ppt_section_attr' );

		// ── Leads ────────────────────────────────────────────────────────────
		add_settings_section( 'ppt_section_leads', __( 'Progressio Leads Capture', $td ), array( $this, 'section_leads_cb' ), $page );
		add_settings_field( 'leads_api_key', __( 'Leads API Key', $td ), array( $this, 'field_leads_api_key' ), $page, 'ppt_section_leads' );
		add_settings_field( 'leads_endpoint', __( 'Leads Endpoint URL', $td ), array( $this, 'field_leads_endpoint' ), $page, 'ppt_section_leads' );

		// ── Field mapping ────────────────────────────────────────────────────
		add_settings_section( 'ppt_section_map', __( 'Lead Field Mapping', $td ), array( $this, 'section_map_cb' ), $page );
		add_settings_field( 'field_map', __( 'Mappings', $td ), array( $this, 'field_map' ), $page, 'ppt_section_map' );
	}

	// ── Section callbacks ────────────────────────────────────────────────────

	public function section_ga4_cb(): void {
		echo '<p>' . esc_html__( 'Connect to your GA4 property. If Google Site Kit is active, gtag.js is already loaded — uncheck "Load gtag.js" to avoid a duplicate tag.', 'progressio-performance-tracker' ) . '</p>';
	}

	public function section_cta_cb(): void {
		echo '<p>' . esc_html__( 'Define the CSS classes for each CTA tier. Alternatively, add data-ppt-cta="primary" (plus an optional data-ppt-label) to any element — attributes are matched first and survive class renames.', 'progressio-performance-tracker' ) . '</p>';
	}

	public function section_forms_cb(): void {
		echo '<p>' . esc_html__( 'Track form submissions as conversion events. Select your form plugin or use Auto-Detect.', 'progressio-performance-tracker' ) . '</p>';
	}

	public function section_extra_cb(): void {
		echo '<p>' . esc_html__( 'Optional micro-conversion and engagement signals that add depth to your GA4 reports.', 'progressio-performance-tracker' ) . '</p>';
	}

	public function section_attr_cb(): void {
		echo '<p>' . esc_html__( 'The first touch (how the visitor originally found the site) is stored in a first-party cookie; the last touch is stored per session. Both are sent with every conversion event and lead.', 'progressio-performance-tracker' ) . '</p>';
	}

	public function section_leads_cb(): void {
		echo '<p>' . esc_html__( 'Send visitor contact data from form submissions to the Progressio dashboard Leads Inbox. Copy the API key from the client record in the Progressio dashboard and paste it here.', 'progressio-performance-tracker' ) . '</p>';
	}

	public function section_map_cb(): void {
		echo '<p>' . esc_html__( 'Optional. By default the plugin guesses which fields hold the name, email, phone and message from their labels. Add a row here to pin the exact fields for a form. Keys match the field ID or label (case-insensitive); join several with | (e.g. "First Name|Last Name").', 'progressio-performance-tracker' ) . '</p>';
	}

	// ── Field callbacks ──────────────────────────────────────────────────────

	private function name( string $key ): string {
		return esc_attr( PPT_OPTION_KEY . '[' . $key . ']' );
	}

	private function constant_notice( string $key ): void {
		if ( $this->is_constant( $key ) ) {
			/* translators: %s: constant name */
			echo '<p class="description ppt-constant">' . sprintf( esc_html__( 'Defined by the %s constant in wp-config.php — the value below is ignored.', 'progressio-performance-tracker' ), '<code>' . esc_html( self::CONSTANTS[ $key ] ) . '</code>' ) . '</p>';
		}
	}

	public function field_measurement_id(): void {
		$val = (string) $this->get( 'measurement_id' );
		echo '<input type="text" name="' . $this->name( 'measurement_id' ) . '" value="' . esc_attr( $val ) . '" placeholder="G-XXXXXXXXXX" class="regular-text" pattern="G-[A-Za-z0-9]{4,20}" ' . disabled( $this->is_constant( 'measurement_id' ), true, false ) . ' />';
		$this->constant_notice( 'measurement_id' );
		echo '<p class="description">' . esc_html__( 'Leave blank if gtag.js is already loaded by another plugin (e.g. Site Kit).', 'progressio-performance-tracker' ) . '</p>';
	}

	public function field_checkbox( array $args ): void {
		$key     = $args['key'];
		$default = ppt_default_options()[ $key ] ?? '0';
		$checked = checked( '1', (string) $this->get( $key, $default ), false );
		echo '<label><input type="checkbox" name="' . $this->name( $key ) . '" value="1" ' . $checked . ' /> ' . esc_html( $args['label'] ) . '</label>';
		if ( ! empty( $args['desc'] ) ) {
			echo '<p class="description">' . esc_html( $args['desc'] ) . '</p>';
		}
	}

	public function field_cta_tier( array $args ): void {
		$tier  = $args['tier'];
		$class = (string) $this->get( "cta_{$tier}_class" );
		$label = (string) $this->get( "cta_{$tier}_label" );
		echo '<div class="ppt-inline-fields">';
		echo '<div><label>' . esc_html__( 'CSS Class', 'progressio-performance-tracker' ) . '</label>';
		echo '<input type="text" name="' . $this->name( "cta_{$tier}_class" ) . '" value="' . esc_attr( $class ) . '" placeholder="e.g. btn--action" class="regular-text" /></div>';
		echo '<div><label>' . esc_html__( 'Event Label', 'progressio-performance-tracker' ) . '</label>';
		echo '<input type="text" name="' . $this->name( "cta_{$tier}_label" ) . '" value="' . esc_attr( $label ) . '" placeholder="e.g. Primary CTA" class="regular-text" /></div>';
		echo '</div>';
	}

	public static function form_plugin_labels(): array {
		return array(
			'auto'         => __( 'Auto-Detect (recommended)', 'progressio-performance-tracker' ),
			'wsform'       => 'WS Form',
			'gravityforms' => 'Gravity Forms',
			'wpforms'      => 'WPForms',
			'cf7'          => 'Contact Form 7',
			'fluentforms'  => 'Fluent Forms',
			'formidable'   => 'Formidable Forms',
			'ninja'        => 'Ninja Forms',
			'elementor'    => 'Elementor Pro Forms',
			'generic'      => __( 'Generic (HTML form submit fallback)', 'progressio-performance-tracker' ),
		);
	}

	public function field_form_plugin(): void {
		$current = (string) $this->get( 'form_plugin', 'auto' );
		echo '<select name="' . $this->name( 'form_plugin' ) . '">';
		foreach ( self::form_plugin_labels() as $val => $label ) {
			echo '<option value="' . esc_attr( $val ) . '" ' . selected( $current, $val, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Auto-Detect binds to every supported plugin\'s success callback and only uses the generic fallback for forms it does not recognise.', 'progressio-performance-tracker' ) . '</p>';
	}

	public function field_attribution_days(): void {
		$val = (int) $this->get( 'attribution_days', 90 );
		echo '<input type="number" min="1" max="365" step="1" name="' . $this->name( 'attribution_days' ) . '" value="' . esc_attr( (string) $val ) . '" class="small-text" /> ';
		echo '<span class="description">' . esc_html__( 'How long the first-touch cookie persists (1–365). Default 90.', 'progressio-performance-tracker' ) . '</span>';
	}

	public function field_leads_api_key(): void {
		$stored = (string) ( $this->options['leads_api_key'] ?? '' );
		$hint   = $stored ? sprintf( '•••••••• %s', esc_html( substr( $stored, -4 ) ) ) : '';

		if ( $this->is_constant( 'leads_api_key' ) ) {
			echo '<input type="password" value="••••••••••••" class="regular-text" disabled />';
			$this->constant_notice( 'leads_api_key' );
			return;
		}

		// The stored key is never echoed back into the page. An empty submit keeps
		// the existing key; the checkbox clears it.
		echo '<input type="password" name="' . $this->name( 'leads_api_key' ) . '" value="" autocomplete="new-password" class="regular-text" placeholder="' . esc_attr( $hint ?: 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx' ) . '" />';
		if ( $stored ) {
			/* translators: %s: last four characters of the saved key */
			echo '<p class="description">' . sprintf( esc_html__( 'A key ending in %s is saved. Leave blank to keep it, or paste a new one to replace it.', 'progressio-performance-tracker' ), '<code>' . esc_html( substr( $stored, -4 ) ) . '</code>' ) . '</p>';
			echo '<label><input type="checkbox" name="' . $this->name( 'leads_api_key_clear' ) . '" value="1" /> ' . esc_html__( 'Remove the saved key', 'progressio-performance-tracker' ) . '</label>';
		} else {
			echo '<p class="description">' . esc_html__( 'Copy from the client record in the Progressio dashboard → Website → Leads Capture API Key.', 'progressio-performance-tracker' ) . '</p>';
		}
	}

	public function field_leads_endpoint(): void {
		$val = (string) $this->get( 'leads_endpoint', PPT_DEFAULT_ENDPOINT );
		echo '<input type="url" name="' . $this->name( 'leads_endpoint' ) . '" value="' . esc_attr( $val ) . '" class="regular-text" ' . disabled( $this->is_constant( 'leads_endpoint' ), true, false ) . ' />';
		$this->constant_notice( 'leads_endpoint' );
		/* translators: %s: comma-separated host list */
		echo '<p class="description">' . sprintf( esc_html__( 'Must be HTTPS on an approved host (%s). Leave as-is unless directed otherwise.', 'progressio-performance-tracker' ), '<code>' . esc_html( implode( ', ', self::allowed_endpoint_hosts() ) ) . '</code>' ) . '</p>';
	}

	public function field_map(): void {
		$rows    = (array) $this->get( 'field_map', array() );
		$plugins = array( 'any' => __( 'Any plugin', 'progressio-performance-tracker' ) ) + array_diff_key( self::form_plugin_labels(), array( 'auto' => 1, 'generic' => 1 ) );
		$roles   = array(
			'name'    => __( 'Name field', 'progressio-performance-tracker' ),
			'email'   => __( 'Email field', 'progressio-performance-tracker' ),
			'phone'   => __( 'Phone field', 'progressio-performance-tracker' ),
			'message' => __( 'Message field', 'progressio-performance-tracker' ),
		);

		$render_row = function ( int $i, array $row ) use ( $plugins, $roles ) {
			$base = PPT_OPTION_KEY . '[field_map][' . $i . ']';
			echo '<tr class="ppt-map-row">';
			echo '<td><select name="' . esc_attr( $base . '[plugin]' ) . '">';
			foreach ( $plugins as $val => $label ) {
				echo '<option value="' . esc_attr( $val ) . '" ' . selected( (string) ( $row['plugin'] ?? 'any' ), $val, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></td>';
			echo '<td><input type="text" name="' . esc_attr( $base . '[form_id]' ) . '" value="' . esc_attr( (string) ( $row['form_id'] ?? '' ) ) . '" placeholder="*" class="small-text" /></td>';
			foreach ( $roles as $role => $label ) {
				echo '<td><input type="text" name="' . esc_attr( $base . '[' . $role . ']' ) . '" value="' . esc_attr( (string) ( $row[ $role ] ?? '' ) ) . '" aria-label="' . esc_attr( $label ) . '" /></td>';
			}
			echo '<td><button type="button" class="button-link-delete ppt-map-remove" aria-label="' . esc_attr__( 'Remove mapping', 'progressio-performance-tracker' ) . '">&times;</button></td>';
			echo '</tr>';
		};

		echo '<table class="widefat ppt-map-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Plugin', 'progressio-performance-tracker' ) . '</th>';
		echo '<th>' . esc_html__( 'Form ID', 'progressio-performance-tracker' ) . '</th>';
		foreach ( $roles as $label ) {
			echo '<th>' . esc_html( $label ) . '</th>';
		}
		echo '<th></th></tr></thead><tbody id="ppt-map-rows">';
		$i = 0;
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$render_row( $i++, $row );
			}
		}
		echo '</tbody></table>';
		echo '<template id="ppt-map-template">';
		$render_row( 9999, array() );
		echo '</template>';
		echo '<p><button type="button" class="button" id="ppt-map-add">' . esc_html__( '+ Add mapping', 'progressio-performance-tracker' ) . '</button></p>';
	}

	/* =========================================================================
	 * SANITIZATION
	 * ======================================================================= */

	/**
	 * Record a validation problem. add_settings_error() only exists once
	 * wp-admin is loaded, so WP-CLI and REST saves collect messages here.
	 */
	private function error( string $code, string $message ): void {
		$this->errors[] = $message;
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( PPT_OPTION_KEY, $code, $message );
		}
	}

	/**
	 * Validation messages from the most recent sanitize_options() call.
	 *
	 * @return string[]
	 */
	public function last_errors(): array {
		return $this->errors;
	}

	/**
	 * Sanitize all options before saving.
	 *
	 * @param mixed $input Raw POST data (an array from the form; anything else
	 *                     from a direct update_option() call).
	 */
	public function sanitize_options( $input ): array {
		$this->errors = array();
		$input    = is_array( $input ) ? $input : array();
		$existing = get_option( PPT_OPTION_KEY, array() );
		$existing = is_array( $existing ) ? $existing : array();
		$clean    = ppt_default_options();
		$td       = 'progressio-performance-tracker';

		// Measurement ID — validate format; reject rather than store junk.
		$mid = strtoupper( sanitize_text_field( (string) ( $input['measurement_id'] ?? '' ) ) );
		if ( '' !== $mid && ! self::is_valid_measurement_id( $mid ) ) {
			$this->error( 'ppt_mid', __( 'Measurement ID must look like G-XXXXXXXXXX. The previous value was kept.', $td ) );
			$mid = (string) ( $existing['measurement_id'] ?? '' );
		}
		$clean['measurement_id'] = $mid;

		foreach ( array( 'load_gtag', 'debug_mode', 'track_forms', 'track_scroll', 'track_outbound', 'track_phone', 'track_email', 'track_downloads', 'track_video' ) as $flag ) {
			$clean[ $flag ] = ! empty( $input[ $flag ] ) ? '1' : '0';
		}

		// sanitize_text_field (not sanitize_html_class) so multi-class values survive.
		foreach ( array( 'primary', 'secondary', 'tertiary' ) as $tier ) {
			$clean[ "cta_{$tier}_class" ] = substr( sanitize_text_field( (string) ( $input[ "cta_{$tier}_class" ] ?? '' ) ), 0, 200 );
			$clean[ "cta_{$tier}_label" ] = substr( sanitize_text_field( (string) ( $input[ "cta_{$tier}_label" ] ?? '' ) ), 0, 100 );
		}

		$form_plugin          = sanitize_text_field( (string) ( $input['form_plugin'] ?? 'auto' ) );
		$clean['form_plugin'] = in_array( $form_plugin, self::FORM_PLUGINS, true ) ? $form_plugin : 'auto';

		$clean['attribution_days'] = max( 1, min( 365, (int) ( $input['attribution_days'] ?? 90 ) ) );

		// API key — blank keeps the existing key; the clear checkbox removes it.
		$key = trim( (string) ( $input['leads_api_key'] ?? '' ) );
		if ( ! empty( $input['leads_api_key_clear'] ) ) {
			$clean['leads_api_key'] = '';
		} elseif ( '' !== $key ) {
			$key = sanitize_text_field( $key );
			if ( ! preg_match( '/^[A-Za-z0-9_\-]{8,128}$/', $key ) ) {
				$this->error( 'ppt_key', __( 'The Leads API key contains unexpected characters and was not saved.', $td ) );
				$clean['leads_api_key'] = (string) ( $existing['leads_api_key'] ?? '' );
			} else {
				$clean['leads_api_key'] = $key;
			}
		} else {
			$clean['leads_api_key'] = (string) ( $existing['leads_api_key'] ?? '' );
		}

		// Endpoint — HTTPS on an approved host only.
		$endpoint = esc_url_raw( trim( (string) ( $input['leads_endpoint'] ?? PPT_DEFAULT_ENDPOINT ) ) );
		if ( '' === $endpoint ) {
			$endpoint = PPT_DEFAULT_ENDPOINT;
		}
		if ( ! self::is_valid_endpoint( $endpoint ) ) {
			/* translators: %s: comma-separated host list */
			$this->error( 'ppt_endpoint', sprintf( __( 'The Leads endpoint must be an HTTPS URL on an approved host (%s). The previous value was kept.', $td ), implode( ', ', self::allowed_endpoint_hosts() ) ) );
			$endpoint = (string) ( $existing['leads_endpoint'] ?? PPT_DEFAULT_ENDPOINT );
		}
		$clean['leads_endpoint'] = $endpoint;

		// Field map — drop rows with no role keys; cap size.
		$map = array();
		foreach ( (array) ( $input['field_map'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$plugin = sanitize_text_field( (string) ( $row['plugin'] ?? 'any' ) );
			$r      = array(
				'plugin'  => in_array( $plugin, self::FORM_PLUGINS, true ) || 'any' === $plugin ? $plugin : 'any',
				'form_id' => substr( sanitize_text_field( (string) ( $row['form_id'] ?? '' ) ), 0, 100 ),
			);
			$has = false;
			foreach ( array( 'name', 'email', 'phone', 'message' ) as $role ) {
				$r[ $role ] = substr( sanitize_text_field( (string) ( $row[ $role ] ?? '' ) ), 0, 200 );
				$has        = $has || '' !== $r[ $role ];
			}
			if ( $has ) {
				$map[] = $r;
			}
			if ( count( $map ) >= 50 ) {
				break;
			}
		}
		$clean['field_map'] = $map;

		return $clean;
	}

	/* =========================================================================
	 * AJAX — test lead / process queue
	 * ======================================================================= */

	private function ajax_guard(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'progressio-performance-tracker' ) ), 403 );
		}
	}

	public function ajax_test_lead(): void {
		$this->ajax_guard();
		$email  = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$result = PPT_Leads::get_instance()->send_test( $email );
		if ( $result['ok'] ) {
			wp_send_json_success( array(
				'message' => sprintf(
					/* translators: 1: HTTP status code, 2: lead id or dash */
					__( 'Test lead accepted (HTTP %1$d, lead ID %2$s).', 'progressio-performance-tracker' ),
					$result['code'],
					$result['lead_id'] ?: '—'
				),
			) );
		}
		wp_send_json_error( array( 'message' => $result['error'] ) );
	}

	public function ajax_process_queue(): void {
		$this->ajax_guard();
		$stats = PPT_Leads::get_instance()->process_queue( true );
		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: 1: sent, 2: failed, 3: dropped, 4: remaining */
				__( 'Queue processed — %1$d sent, %2$d failed, %3$d dropped, %4$d remaining.', 'progressio-performance-tracker' ),
				$stats['sent'],
				$stats['failed'],
				$stats['dropped'],
				$stats['remaining']
			),
		) );
	}

	/* =========================================================================
	 * PAGE RENDER
	 * ======================================================================= */

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap ppt-settings-wrap">
			<h1>
				<span class="dashicons dashicons-chart-line" aria-hidden="true"></span>
				<?php esc_html_e( 'Progressio Performance Tracker', 'progressio-performance-tracker' ); ?>
				<span class="ppt-version">v<?php echo esc_html( PPT_VERSION ); ?></span>
			</h1>
			<p class="ppt-intro"><?php esc_html_e( 'Configure GA4 event tracking for button clicks, form submissions, and visitor attribution.', 'progressio-performance-tracker' ); ?></p>

			<?php settings_errors( PPT_OPTION_KEY ); ?>

			<?php $this->render_health_panel(); ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'ppt_settings_group' );
				do_settings_sections( 'ppt_settings_page' );
				submit_button( __( 'Save Settings', 'progressio-performance-tracker' ) );
				?>
			</form>

			<?php $this->render_event_reference(); ?>
		</div>
		<?php
	}

	/**
	 * Site-health style panel: what's detected, what's misconfigured, and the
	 * current state of lead delivery.
	 */
	private function render_health_panel(): void {
		$leads  = PPT_Leads::get_instance();
		$status = $leads->get_status();
		$queue  = $leads->get_queue();
		$td     = 'progressio-performance-tracker';
		$checks = array();

		// ── GA4 ──────────────────────────────────────────────────────────────
		$mid       = (string) $this->get( 'measurement_id' );
		$load_gtag = '1' === (string) $this->get( 'load_gtag', '1' );
		$other_ga  = array_filter( array(
			'Site Kit'        => defined( 'GOOGLESITEKIT_VERSION' ),
			'MonsterInsights' => defined( 'MONSTERINSIGHTS_VERSION' ),
			'ExactMetrics'    => defined( 'EXACTMETRICS_VERSION' ),
			'GTM4WP'          => defined( 'GTM4WP_VERSION' ),
			'GA Google Analytics' => function_exists( 'ga_google_analytics_tracking_code' ),
		) );

		if ( $mid && self::is_valid_measurement_id( $mid ) ) {
			$checks[] = array( 'ok', sprintf( __( 'Measurement ID %s', $td ), '<code>' . esc_html( $mid ) . '</code>' ) );
		} elseif ( $mid ) {
			$checks[] = array( 'error', __( 'Measurement ID is not in G-XXXXXXXXXX format.', $td ) );
		} elseif ( $load_gtag ) {
			$checks[] = array( 'error', __( 'No Measurement ID — tracker.js is not loading. Enter an ID, or uncheck "Load gtag.js" if another plugin provides gtag.', $td ) );
		} else {
			$checks[] = array( 'warn', __( 'No Measurement ID; relying on another plugin to load gtag.js.', $td ) );
		}

		if ( $other_ga && $load_gtag ) {
			$checks[] = array( 'warn', sprintf( __( '%s is active and this plugin is also loading gtag.js — you probably have a duplicate tag. Uncheck "Load gtag.js".', $td ), esc_html( implode( ', ', array_keys( $other_ga ) ) ) ) );
		} elseif ( $other_ga ) {
			$checks[] = array( 'ok', sprintf( __( 'gtag.js provided by %s.', $td ), esc_html( implode( ', ', array_keys( $other_ga ) ) ) ) );
		} elseif ( ! $load_gtag ) {
			$checks[] = array( 'warn', __( '"Load gtag.js" is off and no known analytics plugin was detected. Events will be dropped unless gtag is loaded some other way (e.g. GTM).', $td ) );
		}

		// ── Forms ────────────────────────────────────────────────────────────
		$active = array_filter( PPT_Leads::detect_form_plugins(), static fn( $p ) => $p['active'] );
		$chosen = (string) $this->get( 'form_plugin', 'auto' );
		if ( $active ) {
			$checks[] = array( 'ok', sprintf( __( 'Form plugins detected: %s', $td ), esc_html( implode( ', ', array_column( $active, 'label' ) ) ) ) );
			if ( 'auto' !== $chosen && 'generic' !== $chosen && ! isset( $active[ $chosen ] ) ) {
				$checks[] = array( 'warn', sprintf( __( 'Form plugin is set to %s but it is not active.', $td ), esc_html( self::form_plugin_labels()[ $chosen ] ?? $chosen ) ) );
			}
		} else {
			$checks[] = array( 'warn', __( 'No supported form plugin detected — only the generic HTML fallback will fire, and no leads will be captured.', $td ) );
		}

		// ── Leads ────────────────────────────────────────────────────────────
		if ( $leads->is_configured() ) {
			$src      = $this->is_constant( 'leads_api_key' ) ? 'wp-config.php' : 'settings';
			$checks[] = array( 'ok', sprintf( __( 'Leads API configured (key from %1$s, endpoint %2$s).', $td ), esc_html( $src ), '<code>' . esc_html( (string) $this->get( 'leads_endpoint' ) ) . '</code>' ) );
			if ( $status['last_sent_at'] ) {
				$checks[] = array( 'ok', sprintf( __( 'Last lead sent %1$s ago (%2$d total).', $td ), esc_html( human_time_diff( $status['last_sent_at'] ) ), (int) $status['total_sent'] ) );
			} else {
				$checks[] = array( 'warn', __( 'No lead has been sent yet.', $td ) );
			}
			if ( $status['last_error'] && $status['last_error_at'] >= $status['last_sent_at'] ) {
				$checks[] = array( 'error', sprintf( __( 'Last delivery error %1$s ago: %2$s', $td ), esc_html( human_time_diff( $status['last_error_at'] ) ), '<code>' . esc_html( $status['last_error'] ) . '</code>' ) );
			}
			if ( $queue ) {
				$checks[] = array( 'warn', sprintf( _n( '%d lead is waiting in the retry queue.', '%d leads are waiting in the retry queue.', count( $queue ), $td ), count( $queue ) ) );
			}
			if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON && $queue ) {
				$checks[] = array( 'warn', __( 'WP-Cron is disabled; queued leads only retry when a system cron runs wp-cron.php.', $td ) );
			}
		} elseif ( (string) $this->get( 'leads_api_key' ) ) {
			$checks[] = array( 'error', __( 'Leads endpoint is invalid — leads are NOT being sent.', $td ) );
		} else {
			$checks[] = array( 'warn', __( 'Leads capture is off (no API key).', $td ) );
		}

		if ( '1' === (string) $this->get( 'debug_mode', '0' ) ) {
			$checks[] = array( 'warn', __( 'Debug mode is on — disable on live client sites.', $td ) );
		}

		$icons = array( 'ok' => 'yes-alt', 'warn' => 'warning', 'error' => 'dismiss' );
		?>
		<div class="ppt-health">
			<h2><?php esc_html_e( 'Status', 'progressio-performance-tracker' ); ?></h2>
			<ul class="ppt-health-list">
				<?php foreach ( $checks as [ $level, $html ] ) : ?>
					<li class="ppt-health-<?php echo esc_attr( $level ); ?>"><span class="dashicons dashicons-<?php echo esc_attr( $icons[ $level ] ); ?>" aria-hidden="true"></span> <?php echo wp_kses( $html, array( 'code' => array() ) ); ?></li>
				<?php endforeach; ?>
			</ul>
			<?php if ( $leads->is_configured() ) : ?>
				<p class="ppt-health-actions">
					<button type="button" class="button" id="ppt-test-lead"><?php esc_html_e( 'Send test lead', 'progressio-performance-tracker' ); ?></button>
					<?php if ( $queue ) : ?>
						<button type="button" class="button" id="ppt-process-queue"><?php esc_html_e( 'Retry queued leads now', 'progressio-performance-tracker' ); ?></button>
					<?php endif; ?>
					<span id="ppt-action-result" role="status" aria-live="polite"></span>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_event_reference(): void {
		$rows = array(
			array( __( 'CTA click', 'progressio-performance-tracker' ), 'cta_click', 'cta_tier, cta_label, button_text, button_class, link_url' ),
			array( __( 'Form submission', 'progressio-performance-tracker' ), 'form_submit', 'form_id, form_title, form_plugin' ),
			array( __( 'Lead captured', 'progressio-performance-tracker' ), 'generate_lead', 'form_id, form_plugin, lead_id (when the Leads API returns one)' ),
			array( __( 'Scroll depth', 'progressio-performance-tracker' ), 'scroll_depth', 'percent_scrolled' ),
			array( __( 'Outbound link', 'progressio-performance-tracker' ), 'outbound_click', 'link_url, link_text, link_domain' ),
			array( __( 'File download', 'progressio-performance-tracker' ), 'file_download', 'file_name, file_extension, link_url, link_text' ),
			array( __( 'Video', 'progressio-performance-tracker' ), 'video_start / video_progress / video_complete', 'video_provider, video_title, video_url, video_percent' ),
			array( __( 'Phone click', 'progressio-performance-tracker' ), 'phone_click', 'phone_number, link_text' ),
			array( __( 'Email click', 'progressio-performance-tracker' ), 'email_click', 'link_text' ),
		);
		?>
		<div class="ppt-event-reference">
			<h2><?php esc_html_e( 'GA4 Event Reference', 'progressio-performance-tracker' ); ?></h2>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Trigger', 'progressio-performance-tracker' ); ?></th>
					<th><?php esc_html_e( 'GA4 Event Name', 'progressio-performance-tracker' ); ?></th>
					<th><?php esc_html_e( 'Event-specific parameters', 'progressio-performance-tracker' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as [ $trigger, $event, $params ] ) : ?>
					<tr><td><?php echo esc_html( $trigger ); ?></td><td><code><?php echo esc_html( $event ); ?></code></td><td><?php echo esc_html( $params ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Every event also carries attribution: traffic_source, traffic_medium, traffic_campaign, traffic_keyword, traffic_content, traffic_channel, gclid/fbclid/msclkid/ttclid (last touch), plus first_source, first_medium, first_channel, landing_page (first touch) and ppt_visitor_id. Register these as custom dimensions with scripts/ga4-setup.', 'progressio-performance-tracker' ); ?></p>
		</div>
		<?php
	}

	public function enqueue_admin_assets( string $hook ): void {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'ppt-admin', PPT_PLUGIN_URL . 'assets/admin.css', array(), PPT_VERSION );
		wp_enqueue_script( 'ppt-admin', PPT_PLUGIN_URL . 'assets/admin.js', array(), PPT_VERSION, true );
		wp_add_inline_script(
			'ppt-admin',
			'window.pptAdmin = ' . wp_json_encode( array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					'sending'    => __( 'Sending…', 'progressio-performance-tracker' ),
					'processing' => __( 'Processing…', 'progressio-performance-tracker' ),
					'failed'     => __( 'Request failed.', 'progressio-performance-tracker' ),
				),
			) ) . ';',
			'before'
		);
	}
}
