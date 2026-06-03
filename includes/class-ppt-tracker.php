<?php
/**
 * Frontend tracking logic for Progressio Performance Tracker.
 *
 * @package Progressio_Performance_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PPT_Tracker
 *
 * Enqueues the tracking script and passes configuration to it via wp_localize_script.
 * Also outputs the gtag.js snippet when required.
 */
class PPT_Tracker {

	/**
	 * Singleton instance.
	 *
	 * @var PPT_Tracker|null
	 */
	private static ?PPT_Tracker $instance = null;

	/**
	 * Settings helper reference.
	 *
	 * @var PPT_Settings
	 */
	private PPT_Settings $settings;

	/**
	 * Get singleton instance.
	 */
	public static function get_instance(): PPT_Tracker {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — wire up hooks.
	 */
	private function __construct() {
		$this->settings = PPT_Settings::get_instance();
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_tracker' ) );
		add_action( 'wp_head', array( $this, 'maybe_output_gtag_snippet' ), 1 );
	}

	/**
	 * Output the gtag.js global site tag in <head> when configured to do so.
	 */
	public function maybe_output_gtag_snippet(): void {
		$mid       = $this->settings->get( 'measurement_id' );
		$load_gtag = $this->settings->get( 'load_gtag', '1' );

		if ( '1' !== $load_gtag || empty( $mid ) ) {
			return;
		}

		$mid = esc_js( $mid );
		?>
		<!-- Progressio Performance Tracker: gtag.js -->
		<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr( $mid ); ?>"></script>
		<script>
			window.dataLayer = window.dataLayer || [];
			function gtag(){dataLayer.push(arguments);}
			gtag('js', new Date());
			gtag('config', '<?php echo $mid; ?>'<?php echo $this->settings->get( 'debug_mode' ) === '1' ? ", { 'debug_mode': true }" : ''; ?>);
		</script>
		<?php
	}

	/**
	 * Enqueue the main tracker JS and pass PHP config to it.
	 */
	public function enqueue_tracker(): void {
		$mid = $this->settings->get( 'measurement_id' );

		// Don't enqueue if no Measurement ID AND gtag load is enabled (nothing to fire to).
		// If load_gtag is off, assume Site Kit or GTM already provides gtag — still enqueue.
		if ( empty( $mid ) && '1' === $this->settings->get( 'load_gtag', '1' ) ) {
			return;
		}

		wp_enqueue_script(
			'ppt-tracker',
			PPT_PLUGIN_URL . 'assets/tracker.js',
			array(), // No dependencies — plain vanilla JS.
			PPT_VERSION,
			true // Load in footer.
		);

		// Build the CTA class list — strip empty values.
		$cta_classes = array_filter( array(
			array(
				'cssClass' => $this->settings->get( 'cta_primary_class' ),
				'label'    => $this->settings->get( 'cta_primary_label', 'Primary CTA' ),
				'tier'     => 'primary',
			),
			array(
				'cssClass' => $this->settings->get( 'cta_secondary_class' ),
				'label'    => $this->settings->get( 'cta_secondary_label', 'Secondary CTA' ),
				'tier'     => 'secondary',
			),
			array(
				'cssClass' => $this->settings->get( 'cta_tertiary_class' ),
				'label'    => $this->settings->get( 'cta_tertiary_label', 'Tertiary CTA' ),
				'tier'     => 'tertiary',
			),
		), static fn( $c ) => ! empty( $c['cssClass'] ) );

		$config = array(
			'measurementId' => $mid,
			'debugMode'     => $this->settings->get( 'debug_mode', '0' ) === '1',
			'ctaClasses'    => array_values( $cta_classes ),
			'trackForms'    => $this->settings->get( 'track_forms', '1' ) === '1',
			'formPlugin'    => $this->settings->get( 'form_plugin', 'auto' ),
			'trackScroll'   => $this->settings->get( 'track_scroll', '1' ) === '1',
			'trackOutbound' => $this->settings->get( 'track_outbound', '1' ) === '1',
			'trackPhone'    => $this->settings->get( 'track_phone', '1' ) === '1',
			'trackEmail'    => $this->settings->get( 'track_email', '1' ) === '1',
			'siteDomain'    => wp_parse_url( home_url(), PHP_URL_HOST ),
		);

		wp_localize_script( 'ppt-tracker', 'pptConfig', $config );
	}
}
