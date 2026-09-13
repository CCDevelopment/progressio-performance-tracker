<?php
/**
 * Frontend tracking for Progressio Performance Tracker.
 *
 * Enqueues tracker.js with its configuration and outputs the gtag.js snippet.
 * Server-side lead capture lives in PPT_Leads.
 *
 * @package Progressio_Performance_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PPT_Tracker {

	private static ?PPT_Tracker $instance = null;
	private PPT_Settings $settings;

	public static function get_instance(): PPT_Tracker {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings = PPT_Settings::get_instance();
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_tracker' ) );
		add_action( 'wp_head', array( $this, 'maybe_output_gtag_snippet' ), 1 );
	}

	private function measurement_id(): string {
		$mid = strtoupper( (string) $this->settings->get( 'measurement_id' ) );
		return PPT_Settings::is_valid_measurement_id( $mid ) ? $mid : '';
	}

	private function flag( string $key, string $default = '1' ): bool {
		return '1' === (string) $this->settings->get( $key, $default );
	}

	/**
	 * Output the gtag.js global site tag in <head>.
	 */
	public function maybe_output_gtag_snippet(): void {
		$mid = $this->measurement_id();
		if ( ! $mid || ! $this->flag( 'load_gtag' ) ) {
			return;
		}

		// $mid is validated against ^G-[A-Z0-9]+$ so it is safe in both contexts,
		// but escape anyway.
		$config = $this->flag( 'debug_mode', '0' ) ? array( 'debug_mode' => true ) : new stdClass();
		?>
		<!-- Progressio Performance Tracker: gtag.js -->
		<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr( $mid ); ?>"></script>
		<script>
			window.dataLayer = window.dataLayer || [];
			function gtag(){dataLayer.push(arguments);}
			gtag('js', new Date());
			gtag('config', '<?php echo esc_js( $mid ); ?>', <?php echo wp_json_encode( $config ); ?>);
		</script>
		<?php
	}

	/**
	 * Enqueue tracker.js and pass its configuration. No secrets are exposed.
	 */
	public function enqueue_tracker(): void {
		$mid = $this->measurement_id();

		// Loading gtag ourselves without an ID means nothing can be sent.
		if ( ! $mid && $this->flag( 'load_gtag' ) ) {
			return;
		}

		wp_enqueue_script( 'ppt-tracker', PPT_PLUGIN_URL . 'assets/tracker.js', array(), PPT_VERSION, true );

		$cta = array();
		foreach ( array( 'primary', 'secondary', 'tertiary' ) as $tier ) {
			$class = trim( (string) $this->settings->get( "cta_{$tier}_class" ) );
			$label = trim( (string) $this->settings->get( "cta_{$tier}_label" ) ) ?: ucfirst( $tier ) . ' CTA';
			if ( '' !== $class ) {
				$cta[] = array( 'cssClass' => $class, 'label' => $label, 'tier' => $tier );
			}
		}

		/**
		 * Filter the file extensions counted as downloads.
		 *
		 * @param string[] $extensions Lower-case extensions without the dot.
		 */
		$download_ext = (array) apply_filters( 'ppt_download_extensions', array(
			'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'txt', 'rtf',
			'zip', 'rar', '7z', 'gz', 'tar',
			'mp3', 'wav', 'm4a', 'mp4', 'mov', 'avi', 'wmv', 'webm',
			'dmg', 'exe', 'pkg', 'apk', 'msi',
			'svg', 'ai', 'psd', 'eps',
		) );

		$config = array(
			'measurementId'      => $mid,
			'debugMode'          => $this->flag( 'debug_mode', '0' ),
			'ctaClasses'         => $cta,
			'trackForms'         => $this->flag( 'track_forms' ),
			'formPlugin'         => (string) $this->settings->get( 'form_plugin', 'auto' ),
			'trackScroll'        => $this->flag( 'track_scroll' ),
			'trackOutbound'      => $this->flag( 'track_outbound' ),
			'trackPhone'         => $this->flag( 'track_phone' ),
			'trackEmail'         => $this->flag( 'track_email' ),
			'trackDownloads'     => $this->flag( 'track_downloads' ),
			'trackVideo'         => $this->flag( 'track_video' ),
			'downloadExtensions' => array_values( array_map( 'strtolower', array_map( 'strval', $download_ext ) ) ),
			'siteDomain'         => PPT_Attribution::strip_www( strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ),
			'attributionDays'    => max( 1, min( 365, (int) $this->settings->get( 'attribution_days', 90 ) ) ),
			'leadsEnabled'       => PPT_Leads::get_instance()->is_configured(),
		);

		/**
		 * Filter the configuration handed to tracker.js.
		 *
		 * @param array $config
		 */
		$config = apply_filters( 'ppt_tracker_config', $config );

		wp_add_inline_script( 'ppt-tracker', 'window.pptConfig = ' . wp_json_encode( $config ) . ';', 'before' );
	}
}
