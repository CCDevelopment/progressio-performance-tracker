<?php
/**
 * Frontend tracking and server-side lead capture for Progressio Performance Tracker.
 *
 * @package Progressio_Performance_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PPT_Tracker
 *
 * Enqueues the tracking script and passes GA4 configuration to it via wp_localize_script.
 * Also outputs the gtag.js snippet and registers server-side form submission hooks
 * so lead data is sent to the Progressio Leads API from PHP — no JS required.
 */
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
		$this->hook_leads();
	}

	/**
	 * Output the gtag.js global site tag in <head>.
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
	 * Enqueue tracker.js and pass GA4 config to it.
	 * API key is intentionally excluded — it stays server-side only.
	 */
	public function enqueue_tracker(): void {
		$mid = $this->settings->get( 'measurement_id' );

		if ( empty( $mid ) && '1' === $this->settings->get( 'load_gtag', '1' ) ) {
			return;
		}

		wp_enqueue_script(
			'ppt-tracker',
			PPT_PLUGIN_URL . 'assets/tracker.js',
			array(),
			PPT_VERSION,
			true
		);

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

		wp_localize_script( 'ppt-tracker', 'pptConfig', array(
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
		) );
	}

	/* =========================================================================
	 * LEAD CAPTURE — server-side PHP hooks
	 * =========================================================================
	 * Each supported form plugin fires an action after a successful submission.
	 * We hook into those actions, extract contact fields, read attribution from
	 * the ppt_attr cookie written by tracker.js, and POST to the Leads API.
	 * Running server-to-server avoids CORS, JS errors, and ad-blockers entirely.
	 * ======================================================================= */

	private function hook_leads(): void {
		$api_key  = $this->settings->get( 'leads_api_key', '' );
		$endpoint = $this->settings->get( 'leads_endpoint', 'https://dash.progressiodev.com/api/leads' );

		if ( ! $api_key || ! $endpoint ) {
			return;
		}

		$plugin = $this->settings->get( 'form_plugin', 'auto' );
		$auto   = ( $plugin === 'auto' );

		// ── WS Form ───────────────────────────────────────────────────────────
		// WS Form submits via /wp-json/ws-form/v1/submit (REST API).
		// We hook rest_post_dispatch so we get both the request params and the
		// confirmed-success response in one place.
		if ( $auto || $plugin === 'wsform' ) {
			add_filter(
				'rest_post_dispatch',
				function ( $response, $server, $request ) use ( $api_key, $endpoint ) {
					if ( false === strpos( $request->get_route(), 'ws-form/v1/submit' ) ) {
						return $response;
					}
					if ( 'POST' !== $request->get_method() ) {
						return $response;
					}

					$data = $response->get_data();
					// Only fire on confirmed success (error === false in WS Form response).
					if ( ! is_array( $data ) || ! empty( $data['error'] ) ) {
						return $response;
					}

					$params  = $request->get_json_params() ?: $request->get_body_params() ?: array();
					$contact = $this->extract_contact( $params );
					$form_id = strval( $params['id'] ?? '' );

					$this->send_lead( $api_key, $endpoint, $contact, $form_id, 'WS Form ' . $form_id );
					return $response;
				},
				10,
				3
			);
		}

		// ── Gravity Forms ─────────────────────────────────────────────────────
		// gform_after_submission fires after the entry is saved and all
		// notifications have been sent. $entry keys are field IDs.
		if ( $auto || $plugin === 'gravityforms' ) {
			add_action(
				'gform_after_submission',
				function ( $entry, $form ) use ( $api_key, $endpoint ) {
					$flat = array();
					foreach ( $form['fields'] as $field ) {
						$label        = strtolower( (string) ( $field->label ?? '' ) );
						$value        = rgar( $entry, (string) $field->id );
						$flat[ $label ] = strval( $value ?? '' );
					}
					$contact = $this->extract_contact( $flat );
					$this->send_lead(
						$api_key,
						$endpoint,
						$contact,
						strval( $form['id'] ?? '' ),
						strval( $form['title'] ?? 'Gravity Form' )
					);
				},
				10,
				2
			);
		}

		// ── WPForms ───────────────────────────────────────────────────────────
		// wpforms_process_complete passes fully-processed fields with labels.
		if ( $auto || $plugin === 'wpforms' ) {
			add_action(
				'wpforms_process_complete',
				function ( $fields, $entry, $form_data, $entry_id ) use ( $api_key, $endpoint ) {
					$flat = array();
					foreach ( $fields as $field ) {
						$label        = strtolower( (string) ( $field['label'] ?? '' ) );
						$flat[ $label ] = strval( $field['value'] ?? '' );
					}
					$contact = $this->extract_contact( $flat );
					$this->send_lead(
						$api_key,
						$endpoint,
						$contact,
						strval( $form_data['id'] ?? '' ),
						strval( $form_data['settings']['form_title'] ?? 'WPForms' )
					);
				},
				10,
				4
			);
		}

		// ── Contact Form 7 ────────────────────────────────────────────────────
		// wpcf7_mail_sent fires after all mail has been sent successfully.
		// Posted data is retrieved from the current submission singleton.
		if ( $auto || $plugin === 'cf7' ) {
			add_action(
				'wpcf7_mail_sent',
				function ( $contact_form ) use ( $api_key, $endpoint ) {
					if ( ! class_exists( 'WPCF7_Submission' ) ) {
						return;
					}
					$submission = WPCF7_Submission::get_instance();
					if ( ! $submission ) {
						return;
					}
					$posted  = array_map( 'strval', $submission->get_posted_data() );
					$contact = $this->extract_contact( $posted );
					$this->send_lead(
						$api_key,
						$endpoint,
						$contact,
						strval( $contact_form->id() ),
						$contact_form->title()
					);
				},
				10,
				1
			);
		}

		// ── Fluent Forms ──────────────────────────────────────────────────────
		// fluentform_submission_inserted fires immediately after DB insert.
		if ( $auto || $plugin === 'fluentforms' ) {
			add_action(
				'fluentform_submission_inserted',
				function ( $entry_id, $form_data, $form ) use ( $api_key, $endpoint ) {
					$flat = array();
					if ( is_array( $form_data ) ) {
						foreach ( $form_data as $key => $value ) {
							$flat[ strtolower( $key ) ] = is_array( $value )
								? implode( ' ', $value )
								: strval( $value );
						}
					}
					$contact = $this->extract_contact( $flat );
					$form_id = is_object( $form ) ? strval( $form->id ?? '' ) : '';
					$this->send_lead( $api_key, $endpoint, $contact, $form_id, 'Fluent Form ' . $form_id );
				},
				10,
				3
			);
		}

		// ── Formidable Forms ──────────────────────────────────────────────────
		// frm_after_create_entry fires after the entry is saved to the DB.
		if ( $auto || $plugin === 'formidable' ) {
			add_action(
				'frm_after_create_entry',
				function ( $entry_id, $form_id ) use ( $api_key, $endpoint ) {
					if ( ! class_exists( 'FrmEntryMeta' ) || ! class_exists( 'FrmField' ) ) {
						return;
					}
					$metas = FrmEntryMeta::get_entry_meta_array( $entry_id );
					$flat  = array();
					foreach ( $metas as $field_id => $value ) {
						$field = FrmField::getOne( $field_id );
						if ( $field ) {
							$flat[ strtolower( $field->name ) ] = is_array( $value )
								? implode( ' ', $value )
								: strval( $value );
						}
					}
					$contact = $this->extract_contact( $flat );
					$this->send_lead( $api_key, $endpoint, $contact, strval( $form_id ), 'Formidable Form ' . $form_id );
				},
				10,
				2
			);
		}

		// ── Ninja Forms ───────────────────────────────────────────────────────
		// ninja_forms_after_submission passes the complete form data array.
		if ( $auto || $plugin === 'ninja' ) {
			add_action(
				'ninja_forms_after_submission',
				function ( $form_data ) use ( $api_key, $endpoint ) {
					$flat = array();
					foreach ( ( $form_data['fields'] ?? array() ) as $field ) {
						$label        = strtolower( (string) ( $field['label'] ?? '' ) );
						$flat[ $label ] = strval( $field['value'] ?? '' );
					}
					$contact = $this->extract_contact( $flat );
					$form_id = strval( $form_data['form_id'] ?? '' );
					$this->send_lead( $api_key, $endpoint, $contact, $form_id, 'Ninja Form ' . $form_id );
				},
				10,
				1
			);
		}
	}

	/**
	 * Extract name/email/phone from a key→value array using label heuristics.
	 *
	 * Works recursively so it handles nested structures (e.g. WS Form's JSON body).
	 * Mirrors the same matching logic as the old tracker.js extractContactFromForm().
	 */
	private function extract_contact( array $fields ): array {
		$email      = null;
		$phone      = null;
		$name       = null;
		$first_name = '';
		$last_name  = '';

		array_walk_recursive(
			$fields,
			function ( $value, $key ) use ( &$email, &$phone, &$name, &$first_name, &$last_name ) {
				$value = trim( (string) $value );
				if ( ! $value ) {
					return;
				}
				$k = strtolower( (string) $key );

				// Email — validate by format first, then fall back to key name.
				if ( ! $email && ( filter_var( $value, FILTER_VALIDATE_EMAIL ) || str_contains( $k, 'email' ) ) ) {
					$email = $value;
					return;
				}

				// Phone — key name heuristic.
				if ( ! $phone && ( str_contains( $k, 'phone' ) || str_contains( $k, 'mobile' ) || str_contains( $k, 'tel' ) ) ) {
					$phone = $value;
					return;
				}

				// First name.
				if ( ! $first_name && str_contains( $k, 'first' ) && str_contains( $k, 'name' ) ) {
					$first_name = $value;
					return;
				}

				// Last name.
				if ( ! $last_name && str_contains( $k, 'last' ) && str_contains( $k, 'name' ) ) {
					$last_name = $value;
					return;
				}

				// Full name catch-all.
				if ( ! $name && str_contains( $k, 'name' ) ) {
					$name = $value;
				}
			}
		);

		if ( ! $name && ( $first_name || $last_name ) ) {
			$name = trim( "$first_name $last_name" );
		}

		return array_filter( array(
			'name'  => $name,
			'email' => $email,
			'phone' => $phone,
		) );
	}

	/**
	 * Read UTM attribution from the ppt_attr cookie written by tracker.js.
	 */
	private function get_attribution(): array {
		if ( empty( $_COOKIE['ppt_attr'] ) ) {
			return array();
		}
		$data = json_decode( stripslashes( $_COOKIE['ppt_attr'] ), true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * POST lead data to the Progressio Leads API.
	 *
	 * Uses blocking=false so the HTTP call is fire-and-forget and never delays
	 * the visitor's form response.
	 */
	private function send_lead( string $api_key, string $endpoint, array $contact, string $form_id, string $form_title ): void {
		if ( empty( $contact ) ) {
			return;
		}

		$attr = $this->get_attribution();

		$payload = array_filter( array(
			'apiKey'      => $api_key,
			'name'        => $contact['name']  ?? null,
			'email'       => $contact['email'] ?? null,
			'phone'       => $contact['phone'] ?? null,
			'source'      => $attr['utm_source']      ?? ( $attr['_referrer_source'] ?? null ),
			'medium'      => $attr['utm_medium']      ?? ( $attr['_referrer_medium'] ?? null ),
			'campaign'    => $attr['utm_campaign']    ?? null,
			'keyword'     => $attr['utm_term']        ?? null,
			'landingPage' => $attr['_page']           ?? null,
			'formId'      => $form_id    ?: null,
			'formTitle'   => $form_title ?: null,
			'pageUrl'     => $attr['_page']           ?? null,
		) );

		wp_remote_post( $endpoint, array(
			'body'      => wp_json_encode( $payload ),
			'headers'   => array( 'Content-Type' => 'application/json' ),
			'blocking'  => false,  // Fire-and-forget — never blocks form response.
			'timeout'   => 5,
			'sslverify' => true,
		) );
	}
}
