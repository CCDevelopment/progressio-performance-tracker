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
 * Registers the admin settings page and handles option saving.
 */
class PPT_Settings {

	/**
	 * Singleton instance.
	 *
	 * @var PPT_Settings|null
	 */
	private static ?PPT_Settings $instance = null;

	/**
	 * Cached settings array.
	 *
	 * @var array
	 */
	private array $options = array();

	/**
	 * Get singleton instance.
	 */
	public static function get_instance(): PPT_Settings {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->options = (array) get_option( PPT_OPTION_KEY, array() );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Return a single option value with optional default.
	 *
	 * @param string $key     Option key.
	 * @param mixed  $default Fallback value.
	 * @return mixed
	 */
	public function get( string $key, $default = '' ) {
		return $this->options[ $key ] ?? $default;
	}

	/**
	 * Register top-level menu page.
	 */
	public function register_menu(): void {
		add_menu_page(
			esc_html__( 'Performance Tracker', 'progressio-performance-tracker' ),
			esc_html__( 'Perf Tracker', 'progressio-performance-tracker' ),
			'manage_options',
			'progressio-performance-tracker',
			array( $this, 'render_settings_page' ),
			'dashicons-chart-line',
			80
		);
	}

	/**
	 * Register settings, sections, and fields.
	 */
	public function register_settings(): void {
		register_setting(
			'ppt_settings_group',
			PPT_OPTION_KEY,
			array(
				'sanitize_callback' => array( $this, 'sanitize_options' ),
			)
		);

		// ── Section: GA4 Connection ──────────────────────────────────────────
		add_settings_section( 'ppt_section_ga4', __( 'GA4 Connection', 'progressio-performance-tracker' ), array( $this, 'section_ga4_cb' ), 'ppt_settings_page' );

		add_settings_field( 'measurement_id', __( 'Measurement ID', 'progressio-performance-tracker' ), array( $this, 'field_measurement_id' ), 'ppt_settings_page', 'ppt_section_ga4' );
		add_settings_field( 'load_gtag', __( 'Load gtag.js', 'progressio-performance-tracker' ), array( $this, 'field_load_gtag' ), 'ppt_settings_page', 'ppt_section_ga4' );
		add_settings_field( 'debug_mode', __( 'Debug Mode', 'progressio-performance-tracker' ), array( $this, 'field_debug_mode' ), 'ppt_settings_page', 'ppt_section_ga4' );

		// ── Section: CTA Button Tracking ─────────────────────────────────────
		add_settings_section( 'ppt_section_cta', __( 'CTA Button Tracking', 'progressio-performance-tracker' ), array( $this, 'section_cta_cb' ), 'ppt_settings_page' );

		foreach ( array( 'primary', 'secondary', 'tertiary' ) as $tier ) {
			add_settings_field(
				"cta_{$tier}",
				/* translators: %s: tier name */
				sprintf( __( '%s CTA', 'progressio-performance-tracker' ), ucfirst( $tier ) ),
				array( $this, 'field_cta_tier' ),
				'ppt_settings_page',
				'ppt_section_cta',
				array( 'tier' => $tier )
			);
		}

		// ── Section: Form Tracking ───────────────────────────────────────────
		add_settings_section( 'ppt_section_forms', __( 'Form Submission Tracking', 'progressio-performance-tracker' ), array( $this, 'section_forms_cb' ), 'ppt_settings_page' );

		add_settings_field( 'track_forms', __( 'Enable Form Tracking', 'progressio-performance-tracker' ), array( $this, 'field_track_forms' ), 'ppt_settings_page', 'ppt_section_forms' );
		add_settings_field( 'form_plugin', __( 'Form Plugin', 'progressio-performance-tracker' ), array( $this, 'field_form_plugin' ), 'ppt_settings_page', 'ppt_section_forms' );

		// ── Section: Additional Tracking ─────────────────────────────────────
		add_settings_section( 'ppt_section_extra', __( 'Additional Tracking', 'progressio-performance-tracker' ), array( $this, 'section_extra_cb' ), 'ppt_settings_page' );

		add_settings_field( 'track_scroll', __( 'Scroll Depth (25/50/75/100%)', 'progressio-performance-tracker' ), array( $this, 'field_track_scroll' ), 'ppt_settings_page', 'ppt_section_extra' );
		add_settings_field( 'track_outbound', __( 'Outbound Link Clicks', 'progressio-performance-tracker' ), array( $this, 'field_track_outbound' ), 'ppt_settings_page', 'ppt_section_extra' );
		add_settings_field( 'track_phone', __( 'Phone Number Clicks', 'progressio-performance-tracker' ), array( $this, 'field_track_phone' ), 'ppt_settings_page', 'ppt_section_extra' );
		add_settings_field( 'track_email', __( 'Email Address Clicks', 'progressio-performance-tracker' ), array( $this, 'field_track_email' ), 'ppt_settings_page', 'ppt_section_extra' );
	}

	// ── Section callbacks ────────────────────────────────────────────────────

	public function section_ga4_cb(): void {
		echo '<p>' . esc_html__( 'Connect to your GA4 property. If Google Site Kit is active, gtag.js is already loaded — uncheck "Load gtag.js" to avoid a duplicate tag.', 'progressio-performance-tracker' ) . '</p>';
	}

	public function section_cta_cb(): void {
		echo '<p>' . esc_html__( 'Define the CSS classes for each CTA tier. Events will be sent to GA4 with the label you specify so reports are human-readable.', 'progressio-performance-tracker' ) . '</p>';
	}

	public function section_forms_cb(): void {
		echo '<p>' . esc_html__( 'Track form submissions as conversion events. Select your form plugin or use Auto-Detect.', 'progressio-performance-tracker' ) . '</p>';
	}

	public function section_extra_cb(): void {
		echo '<p>' . esc_html__( 'Optional micro-conversion and engagement signals that add depth to your GA4 reports.', 'progressio-performance-tracker' ) . '</p>';
	}

	// ── Field callbacks ──────────────────────────────────────────────────────

	public function field_measurement_id(): void {
		$val = esc_attr( $this->get( 'measurement_id' ) );
		echo '<input type="text" name="' . esc_attr( PPT_OPTION_KEY ) . '[measurement_id]" value="' . $val . '" placeholder="G-XXXXXXXXXX" class="regular-text" />';
		echo '<p class="description">' . esc_html__( 'Leave blank if gtag.js is already loaded by another plugin (e.g. Site Kit).', 'progressio-performance-tracker' ) . '</p>';
	}

	public function field_load_gtag(): void {
		$checked = checked( '1', $this->get( 'load_gtag', '1' ), false );
		echo '<label><input type="checkbox" name="' . esc_attr( PPT_OPTION_KEY ) . '[load_gtag]" value="1" ' . $checked . ' /> ' . esc_html__( 'Load the gtag.js library from this plugin', 'progressio-performance-tracker' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Uncheck if Site Kit or another plugin already loads gtag.js.', 'progressio-performance-tracker' ) . '</p>';
	}

	public function field_debug_mode(): void {
		$checked = checked( '1', $this->get( 'debug_mode', '0' ), false );
		echo '<label><input type="checkbox" name="' . esc_attr( PPT_OPTION_KEY ) . '[debug_mode]" value="1" ' . $checked . ' /> ' . esc_html__( 'Enable debug mode (logs events to browser console)', 'progressio-performance-tracker' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Disable on live client sites. Uses GA4 debug_mode parameter so events appear in DebugView.', 'progressio-performance-tracker' ) . '</p>';
	}

	public function field_cta_tier( array $args ): void {
		$tier  = $args['tier'];
		$class = esc_attr( $this->get( "cta_{$tier}_class" ) );
		$label = esc_attr( $this->get( "cta_{$tier}_label" ) );
		echo '<div style="display:flex;gap:12px;align-items:center;">';
		echo '<div>';
		echo '<label style="display:block;font-weight:600;margin-bottom:4px;">' . esc_html__( 'CSS Class', 'progressio-performance-tracker' ) . '</label>';
		echo '<input type="text" name="' . esc_attr( PPT_OPTION_KEY ) . '[cta_' . $tier . '_class]" value="' . $class . '" placeholder="e.g. btn--action" class="regular-text" />';
		echo '</div>';
		echo '<div>';
		echo '<label style="display:block;font-weight:600;margin-bottom:4px;">' . esc_html__( 'Event Label', 'progressio-performance-tracker' ) . '</label>';
		echo '<input type="text" name="' . esc_attr( PPT_OPTION_KEY ) . '[cta_' . $tier . '_label]" value="' . $label . '" placeholder="e.g. Primary CTA" class="regular-text" />';
		echo '</div>';
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'The label appears in GA4 event parameters for easy filtering.', 'progressio-performance-tracker' ) . '</p>';
	}

	public function field_track_forms(): void {
		$checked = checked( '1', $this->get( 'track_forms', '1' ), false );
		echo '<label><input type="checkbox" name="' . esc_attr( PPT_OPTION_KEY ) . '[track_forms]" value="1" ' . $checked . ' /> ' . esc_html__( 'Track form submissions as GA4 events', 'progressio-performance-tracker' ) . '</label>';
	}

	public function field_form_plugin(): void {
		$current = $this->get( 'form_plugin', 'auto' );
		$plugins  = array(
			'auto'          => __( 'Auto-Detect (recommended)', 'progressio-performance-tracker' ),
			'wsform'        => 'WS Form',
			'gravityforms'  => 'Gravity Forms',
			'wpforms'       => 'WPForms',
			'cf7'           => 'Contact Form 7',
			'fluentforms'   => 'Fluent Forms',
			'formidable'    => 'Formidable Forms',
			'ninja'         => 'Ninja Forms',
			'generic'       => __( 'Generic (HTML form submit fallback)', 'progressio-performance-tracker' ),
		);
		echo '<select name="' . esc_attr( PPT_OPTION_KEY ) . '[form_plugin]">';
		foreach ( $plugins as $val => $label ) {
			$selected = selected( $current, $val, false );
			echo '<option value="' . esc_attr( $val ) . '" ' . $selected . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Auto-Detect checks for active plugins and binds to the correct success callback. Use Generic as a universal fallback.', 'progressio-performance-tracker' ) . '</p>';
	}

	public function field_track_scroll(): void {
		$checked = checked( '1', $this->get( 'track_scroll', '1' ), false );
		echo '<label><input type="checkbox" name="' . esc_attr( PPT_OPTION_KEY ) . '[track_scroll]" value="1" ' . $checked . ' /> ' . esc_html__( 'Fire events at 25%, 50%, 75%, and 100% scroll depth', 'progressio-performance-tracker' ) . '</label>';
	}

	public function field_track_outbound(): void {
		$checked = checked( '1', $this->get( 'track_outbound', '1' ), false );
		echo '<label><input type="checkbox" name="' . esc_attr( PPT_OPTION_KEY ) . '[track_outbound]" value="1" ' . $checked . ' /> ' . esc_html__( 'Track clicks on external links', 'progressio-performance-tracker' ) . '</label>';
	}

	public function field_track_phone(): void {
		$checked = checked( '1', $this->get( 'track_phone', '1' ), false );
		echo '<label><input type="checkbox" name="' . esc_attr( PPT_OPTION_KEY ) . '[track_phone]" value="1" ' . $checked . ' /> ' . esc_html__( 'Track tel: link clicks (phone number taps)', 'progressio-performance-tracker' ) . '</label>';
	}

	public function field_track_email(): void {
		$checked = checked( '1', $this->get( 'track_email', '1' ), false );
		echo '<label><input type="checkbox" name="' . esc_attr( PPT_OPTION_KEY ) . '[track_email]" value="1" ' . $checked . ' /> ' . esc_html__( 'Track mailto: link clicks', 'progressio-performance-tracker' ) . '</label>';
	}

	// ── Sanitization ─────────────────────────────────────────────────────────

	/**
	 * Sanitize all options before saving.
	 *
	 * @param array $input Raw POST data.
	 * @return array Sanitized options.
	 */
	public function sanitize_options( array $input ): array {
		$clean = array();

		$clean['measurement_id']      = sanitize_text_field( $input['measurement_id'] ?? '' );
		$clean['load_gtag']           = isset( $input['load_gtag'] ) ? '1' : '0';
		$clean['debug_mode']          = isset( $input['debug_mode'] ) ? '1' : '0';
		// sanitize_text_field (not sanitize_html_class) so space-separated multi-class values are preserved.
		$clean['cta_primary_class']   = sanitize_text_field( $input['cta_primary_class'] ?? '' );
		$clean['cta_primary_label']   = sanitize_text_field( $input['cta_primary_label'] ?? '' );
		$clean['cta_secondary_class'] = sanitize_text_field( $input['cta_secondary_class'] ?? '' );
		$clean['cta_secondary_label'] = sanitize_text_field( $input['cta_secondary_label'] ?? '' );
		$clean['cta_tertiary_class']  = sanitize_text_field( $input['cta_tertiary_class'] ?? '' );
		$clean['cta_tertiary_label']  = sanitize_text_field( $input['cta_tertiary_label'] ?? '' );
		$clean['track_forms']         = isset( $input['track_forms'] ) ? '1' : '0';
		$allowed_form_plugins         = array( 'auto', 'wsform', 'gravityforms', 'wpforms', 'cf7', 'fluentforms', 'formidable', 'ninja', 'generic' );
		$form_plugin_input            = sanitize_text_field( $input['form_plugin'] ?? 'auto' );
		$clean['form_plugin']         = in_array( $form_plugin_input, $allowed_form_plugins, true ) ? $form_plugin_input : 'auto';
		$clean['track_scroll']        = isset( $input['track_scroll'] ) ? '1' : '0';
		$clean['track_outbound']      = isset( $input['track_outbound'] ) ? '1' : '0';
		$clean['track_phone']         = isset( $input['track_phone'] ) ? '1' : '0';
		$clean['track_email']         = isset( $input['track_email'] ) ? '1' : '0';

		return $clean;
	}

	// ── Admin page render ────────────────────────────────────────────────────

	/**
	 * Render the settings page HTML.
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap ppt-settings-wrap">
			<h1>
				<span class="dashicons dashicons-chart-line" style="font-size:28px;vertical-align:middle;margin-right:8px;color:#800000;"></span>
				<?php esc_html_e( 'Progressio Performance Tracker', 'progressio-performance-tracker' ); ?>
			</h1>
			<p style="color:#666;margin-top:0;"><?php esc_html_e( 'Configure GA4 event tracking for button clicks, form submissions, and visitor attribution.', 'progressio-performance-tracker' ); ?></p>

			<?php settings_errors( PPT_OPTION_KEY ); ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'ppt_settings_group' );
				do_settings_sections( 'ppt_settings_page' );
				submit_button( __( 'Save Settings', 'progressio-performance-tracker' ) );
				?>
			</form>

			<hr />
			<div class="ppt-event-reference" style="background:#f9f9f9;border:1px solid #ddd;border-radius:6px;padding:20px;margin-top:24px;max-width:700px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'GA4 Event Reference', 'progressio-performance-tracker' ); ?></h2>
				<table class="widefat striped" style="font-size:13px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Trigger', 'progressio-performance-tracker' ); ?></th>
							<th><?php esc_html_e( 'GA4 Event Name', 'progressio-performance-tracker' ); ?></th>
							<th><?php esc_html_e( 'Key Parameters Sent', 'progressio-performance-tracker' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr><td><?php esc_html_e( 'Primary CTA click', 'progressio-performance-tracker' ); ?></td><td><code>cta_click</code></td><td>cta_tier, button_text, button_class, page_location, traffic_source, traffic_medium, traffic_campaign</td></tr>
						<tr><td><?php esc_html_e( 'Secondary CTA click', 'progressio-performance-tracker' ); ?></td><td><code>cta_click</code></td><td>cta_tier, button_text, button_class, page_location, traffic_source, …</td></tr>
						<tr><td><?php esc_html_e( 'Tertiary CTA click', 'progressio-performance-tracker' ); ?></td><td><code>cta_click</code></td><td>cta_tier, button_text, button_class, page_location, traffic_source, …</td></tr>
						<tr><td><?php esc_html_e( 'Form submission', 'progressio-performance-tracker' ); ?></td><td><code>form_submit</code></td><td>form_id, form_title, form_plugin, page_location, traffic_source, …</td></tr>
						<tr><td><?php esc_html_e( 'Scroll depth', 'progressio-performance-tracker' ); ?></td><td><code>scroll_depth</code></td><td>percent_scrolled, page_location</td></tr>
						<tr><td><?php esc_html_e( 'Outbound link', 'progressio-performance-tracker' ); ?></td><td><code>outbound_click</code></td><td>link_url, link_text, page_location</td></tr>
						<tr><td><?php esc_html_e( 'Phone click', 'progressio-performance-tracker' ); ?></td><td><code>phone_click</code></td><td>phone_number, page_location, traffic_source, …</td></tr>
						<tr><td><?php esc_html_e( 'Email click', 'progressio-performance-tracker' ); ?></td><td><code>email_click</code></td><td>email_address, page_location, traffic_source, …</td></tr>
					</tbody>
				</table>
				<p style="margin-bottom:0;color:#555;font-size:12px;"><?php esc_html_e( 'All conversion events (cta_click, form_submit, phone_click, email_click) include traffic attribution parameters so you can tie each conversion back to its source keyword or campaign in GA4.', 'progressio-performance-tracker' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueue admin-only styles.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( string $hook ): void {
		if ( 'toplevel_page_progressio-performance-tracker' !== $hook ) {
			return;
		}
		wp_add_inline_style(
			'wp-admin',
			'.ppt-settings-wrap h2{color:#1E252B;border-bottom:2px solid #800000;padding-bottom:6px;}
			 .ppt-settings-wrap .form-table th{width:220px;}
			 .ppt-settings-wrap input[type=text]:focus{border-color:#800000;box-shadow:0 0 0 1px #800000;}'
		);
	}
}
