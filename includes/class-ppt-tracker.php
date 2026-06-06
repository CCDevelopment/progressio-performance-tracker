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
		$endpoint = $this->settings->get( 'leads_endpoint', 'https://dashboard.progressiodev.com/api/leads' );

		if ( ! $api_key || ! $endpoint ) {
			return;
		}

		$plugin = $this->settings->get( 'form_plugin', 'auto' );
		$auto   = ( $plugin === 'auto' );

		// ── WS Form ───────────────────────────────────────────────────────────
		// wsf_submit_post_complete fires server-side after a form has been
		// successfully posted — regardless of transport (AJAX or otherwise), so
		// it works where the old REST-dispatch interception did not. The handler
		// receives the WS Form submit object; field values live in $submit->meta
		// keyed by "field_{id}", and the form definition (for labels/types) is on
		// $submit->form_object.
		if ( $auto || $plugin === 'wsform' ) {
			add_action(
				'wsf_submit_post_complete',
				function ( $submit ) use ( $api_key, $endpoint ) {
					if ( ! is_object( $submit ) || empty( $submit->meta ) || ! is_array( $submit->meta ) ) {
						return;
					}

					// Build an id → { label, type } map from the form definition.
					$field_map = $this->wsform_field_map( $submit->form_object ?? null );

					$flat = array();
					foreach ( $submit->meta as $meta_key => $meta ) {
						$value = is_array( $meta ) ? ( $meta['value'] ?? '' ) : $meta;
						if ( is_array( $value ) ) {
							$value = implode( ' ', array_map( 'strval', $value ) );
						}
						$value = strval( $value );
						if ( '' === $value ) {
							continue;
						}

						// meta keys look like "field_123"; map back to the field's
						// label/type so extract_contact's heuristics can match.
						$field_id = ( 0 === strpos( (string) $meta_key, 'field_' ) )
							? substr( (string) $meta_key, 6 )
							: (string) $meta_key;

						$info  = $field_map[ $field_id ] ?? array();
						$type  = strtolower( (string) ( $info['type'] ?? '' ) );
						$label = strtolower( (string) ( $info['label'] ?? '' ) );

						// Field-type hints make matching reliable even when the
						// label is generic (e.g. a bare "Email" placeholder).
						if ( 'email' === $type ) {
							$label = $label ? $label . ' email' : 'email';
						} elseif ( in_array( $type, array( 'tel', 'phone' ), true ) ) {
							$label = $label ? $label . ' phone' : 'phone';
						}

						// Fall back to the meta key so values are never dropped.
						$key = $label ?: strtolower( (string) $meta_key );
						$flat[ $key ] = $value;
					}

					$contact = $this->extract_contact( $flat );
					$form_id = strval( $submit->form_id ?? ( $submit->form_object->id ?? '' ) );
					$title   = isset( $submit->form_object->label ) && $submit->form_object->label
						? strval( $submit->form_object->label )
						: 'WS Form ' . $form_id;

					$this->send_lead( $api_key, $endpoint, $contact, $form_id, $title );
				},
				20,
				1
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
	 * Build a field id → { label, type } map from a WS Form form object.
	 *
	 * WS Form nests fields under groups → sections → fields. We walk that
	 * structure defensively (arrays or objects, any missing level) so a single
	 * unexpected shape can never fatal the submission handler.
	 */
	private function wsform_field_map( $form_object ): array {
		$map = array();
		if ( empty( $form_object ) ) {
			return $map;
		}

		$groups = $form_object->groups ?? array();
		foreach ( (array) $groups as $group ) {
			$sections = ( is_object( $group ) ? ( $group->sections ?? array() ) : ( $group['sections'] ?? array() ) );
			foreach ( (array) $sections as $section ) {
				$fields = ( is_object( $section ) ? ( $section->fields ?? array() ) : ( $section['fields'] ?? array() ) );
				foreach ( (array) $fields as $field ) {
					$id    = is_object( $field ) ? ( $field->id ?? null ) : ( $field['id'] ?? null );
					$label = is_object( $field ) ? ( $field->label ?? '' ) : ( $field['label'] ?? '' );
					$type  = is_object( $field ) ? ( $field->type ?? '' ) : ( $field['type'] ?? '' );
					if ( null !== $id ) {
						$map[ (string) $id ] = array(
							'label' => (string) $label,
							'type'  => (string) $type,
						);
					}
				}
			}
		}

		return $map;
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
