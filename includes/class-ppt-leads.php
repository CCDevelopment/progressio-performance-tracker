<?php
/**
 * Server-side lead capture for Progressio Performance Tracker.
 *
 * Each supported form plugin fires an action after a successful submission.
 * We hook those actions, normalise the submitted fields, extract contact
 * details (explicit per-form mapping first, heuristics second), attach
 * first/last-touch attribution from the tracker.js cookies, and POST the
 * result to the Progressio Leads API. Failed deliveries are queued and
 * retried via WP-Cron with exponential backoff.
 *
 * @package Progressio_Performance_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PPT_Leads {

	private static ?PPT_Leads $instance = null;
	private PPT_Settings $settings;
	private string $api_key  = '';
	private string $endpoint = '';

	/** Retry delays (seconds) indexed by attempt number. */
	private const BACKOFF = array( 300, 900, 3600, 21600, 86400 );
	private const MAX_ATTEMPTS = 5;
	private const MAX_QUEUE = 100;
	private const LAST_LEAD_COOKIE = 'ppt_last_lead';

	public static function get_instance(): PPT_Leads {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings = PPT_Settings::get_instance();
		$this->api_key  = (string) $this->settings->get( 'leads_api_key', '' );
		$this->endpoint = (string) $this->settings->get( 'leads_endpoint', PPT_DEFAULT_ENDPOINT );

		add_action( PPT_CRON_HOOK, array( $this, 'process_queue' ) );

		if ( $this->is_configured() ) {
			$this->hook_forms();
		}
	}

	/**
	 * True when an API key is set and the endpoint passes validation.
	 */
	public function is_configured(): bool {
		return '' !== $this->api_key && PPT_Settings::is_valid_endpoint( $this->endpoint );
	}

	/* =========================================================================
	 * FORM PLUGIN HOOKS
	 * Every handler normalises its plugin's data into a list of fields shaped
	 * as [ 'id' => string, 'label' => string, 'type' => string, 'value' => string ]
	 * and hands off to handle_submission().
	 * ======================================================================= */

	private function hook_forms(): void {
		$plugin = (string) $this->settings->get( 'form_plugin', 'auto' );
		$auto   = ( 'auto' === $plugin );
		$want   = static fn( string $slug ) => $auto || $plugin === $slug;

		// ── WS Form ───────────────────────────────────────────────────────────
		// wsf_submit_post_complete fires server-side after a successful post,
		// regardless of transport. Field values live in $submit->meta keyed by
		// "field_{id}"; labels/types come from $submit->form_object.
		if ( $want( 'wsform' ) ) {
			add_action( 'wsf_submit_post_complete', function ( $submit ) {
				if ( ! is_object( $submit ) || empty( $submit->meta ) || ! is_array( $submit->meta ) ) {
					return;
				}
				$map    = $this->wsform_field_map( $submit->form_object ?? null );
				$fields = array();
				foreach ( $submit->meta as $meta_key => $meta ) {
					$value    = is_array( $meta ) ? ( $meta['value'] ?? '' ) : $meta;
					$field_id = str_starts_with( (string) $meta_key, 'field_' ) ? substr( (string) $meta_key, 6 ) : (string) $meta_key;
					$info     = $map[ $field_id ] ?? array();
					$fields[] = $this->field( $field_id, $info['label'] ?? '', $info['type'] ?? '', $value );
				}
				$form_id = (string) ( $submit->form_id ?? ( $submit->form_object->id ?? '' ) );
				$title   = ! empty( $submit->form_object->label ) ? (string) $submit->form_object->label : 'WS Form ' . $form_id;
				$extra   = array();
				if ( isset( $submit->spam_level ) && is_numeric( $submit->spam_level ) ) {
					$extra['spamScore'] = (int) $submit->spam_level;
					$extra['isSpam']    = (int) $submit->spam_level >= 50;
				}
				$this->handle_submission( 'wsform', $form_id, $title, $fields, $extra );
			}, 20, 1 );
		}

		// ── Gravity Forms ─────────────────────────────────────────────────────
		if ( $want( 'gravityforms' ) ) {
			add_action( 'gform_after_submission', function ( $entry, $form ) {
				if ( ! is_array( $entry ) || ! is_array( $form ) ) {
					return;
				}
				$fields = array();
				foreach ( (array) ( $form['fields'] ?? array() ) as $field ) {
					if ( ! is_object( $field ) ) {
						continue;
					}
					$label = (string) ( $field->label ?? '' );
					$type  = (string) ( $field->type ?? '' );
					if ( ! empty( $field->inputs ) && is_array( $field->inputs ) ) {
						// Multi-input fields (Name, Address): one entry per sub-input.
						foreach ( $field->inputs as $input ) {
							$iid      = (string) ( $input['id'] ?? '' );
							$ilabel   = trim( $label . ' ' . (string) ( $input['label'] ?? '' ) );
							$fields[] = $this->field( $iid, $ilabel, $type, $entry[ $iid ] ?? '' );
						}
					}
					$fields[] = $this->field( (string) $field->id, $label, $type, $entry[ (string) $field->id ] ?? '' );
				}
				$extra = array();
				if ( isset( $entry['status'] ) ) {
					$extra['isSpam'] = ( 'spam' === $entry['status'] );
				}
				$this->handle_submission( 'gravityforms', (string) ( $form['id'] ?? '' ), (string) ( $form['title'] ?? 'Gravity Form' ), $fields, $extra );
			}, 10, 2 );
		}

		// ── WPForms ───────────────────────────────────────────────────────────
		if ( $want( 'wpforms' ) ) {
			add_action( 'wpforms_process_complete', function ( $fields_in, $entry, $form_data, $entry_id ) {
				$fields = array();
				foreach ( (array) $fields_in as $f ) {
					if ( ! is_array( $f ) ) {
						continue;
					}
					$id    = (string) ( $f['id'] ?? '' );
					$label = (string) ( $f['name'] ?? '' );
					$type  = (string) ( $f['type'] ?? '' );
					$fields[] = $this->field( $id, $label, $type, $f['value'] ?? '' );
					foreach ( array( 'first', 'middle', 'last' ) as $part ) {
						if ( isset( $f[ $part ] ) && '' !== $f[ $part ] ) {
							$fields[] = $this->field( $id . '.' . $part, $label . ' ' . $part, $type, $f[ $part ] );
						}
					}
				}
				$this->handle_submission(
					'wpforms',
					(string) ( $form_data['id'] ?? '' ),
					(string) ( $form_data['settings']['form_title'] ?? 'WPForms' ),
					$fields
				);
			}, 10, 4 );
		}

		// ── Contact Form 7 ────────────────────────────────────────────────────
		// wpcf7_mail_sent only fires for non-spam submissions that mailed OK.
		if ( $want( 'cf7' ) ) {
			add_action( 'wpcf7_mail_sent', function ( $contact_form ) {
				if ( ! class_exists( 'WPCF7_Submission' ) ) {
					return;
				}
				$submission = WPCF7_Submission::get_instance();
				if ( ! $submission ) {
					return;
				}
				$types = array();
				if ( method_exists( $contact_form, 'scan_form_tags' ) ) {
					foreach ( (array) $contact_form->scan_form_tags() as $tag ) {
						if ( is_object( $tag ) && ! empty( $tag->name ) ) {
							$types[ $tag->name ] = (string) ( $tag->basetype ?? ( $tag->type ?? '' ) );
						}
					}
				}
				$fields = array();
				foreach ( (array) $submission->get_posted_data() as $key => $value ) {
					$fields[] = $this->field( (string) $key, (string) $key, $types[ $key ] ?? '', $value );
				}
				$this->handle_submission( 'cf7', (string) $contact_form->id(), (string) $contact_form->title(), $fields, array( 'isSpam' => false ) );
			}, 10, 1 );
		}

		// ── Fluent Forms ──────────────────────────────────────────────────────
		if ( $want( 'fluentforms' ) ) {
			add_action( 'fluentform/submission_inserted', array( $this, 'fluent_handler' ), 10, 3 );
			add_action( 'fluentform_submission_inserted', array( $this, 'fluent_handler' ), 10, 3 );
		}

		// ── Formidable Forms ──────────────────────────────────────────────────
		if ( $want( 'formidable' ) ) {
			add_action( 'frm_after_create_entry', function ( $entry_id, $form_id ) {
				if ( ! class_exists( 'FrmEntryMeta' ) || ! class_exists( 'FrmField' ) ) {
					return;
				}
				$fields = array();
				foreach ( (array) FrmEntryMeta::get_entry_meta_array( $entry_id ) as $field_id => $value ) {
					$field = FrmField::getOne( $field_id );
					if ( $field ) {
						$fields[] = $this->field( (string) $field_id, (string) ( $field->name ?? '' ), (string) ( $field->type ?? '' ), $value );
					}
				}
				$this->handle_submission( 'formidable', (string) $form_id, 'Formidable Form ' . $form_id, $fields );
			}, 10, 2 );
		}

		// ── Elementor Pro Forms ───────────────────────────────────────────────
		// Fires after validation, so reCAPTCHA / required-field failures never
		// reach us. Each field carries title (label), type and value.
		if ( $want( 'elementor' ) ) {
			add_action( 'elementor_pro/forms/new_record', function ( $record, $handler ) {
				if ( ! is_object( $record ) || ! method_exists( $record, 'get' ) ) {
					return;
				}
				$raw = $record->get( 'fields' );
				if ( ! is_array( $raw ) ) {
					return;
				}
				$fields = array();
				foreach ( $raw as $field_id => $f ) {
					$fields[] = $this->field(
						(string) $field_id,
						is_array( $f ) ? (string) ( $f['title'] ?? '' ) : '',
						is_array( $f ) ? (string) ( $f['type'] ?? '' ) : '',
						is_array( $f ) ? ( $f['value'] ?? '' ) : $f
					);
				}
				$settings = $record->get( 'form_settings' );
				$settings = is_array( $settings ) ? $settings : array();
				$title    = (string) ( $settings['form_name'] ?? '' );
				$form_id  = (string) ( $settings['id'] ?? ( $settings['form_id'] ?? '' ) );
				$this->handle_submission( 'elementor', $form_id ?: $title, $title ?: 'Elementor Form', $fields );
			}, 10, 2 );
		}

		// ── Ninja Forms ───────────────────────────────────────────────────────
		if ( $want( 'ninja' ) ) {
			add_action( 'ninja_forms_after_submission', function ( $form_data ) {
				$fields = array();
				foreach ( (array) ( $form_data['fields'] ?? array() ) as $f ) {
					if ( ! is_array( $f ) ) {
						continue;
					}
					$fields[] = $this->field( (string) ( $f['key'] ?? ( $f['id'] ?? '' ) ), (string) ( $f['label'] ?? '' ), (string) ( $f['type'] ?? '' ), $f['value'] ?? '' );
				}
				$form_id = (string) ( $form_data['form_id'] ?? '' );
				$this->handle_submission( 'ninja', $form_id, 'Ninja Form ' . $form_id, $fields );
			}, 10, 1 );
		}
	}

	/**
	 * Fluent Forms handler. Registered under both the legacy and the current
	 * (slash-namespaced) action names; guarded so a site firing both only
	 * sends once.
	 */
	public function fluent_handler( $entry_id, $form_data, $form ): void {
		static $seen = array();
		if ( isset( $seen[ (string) $entry_id ] ) ) {
			return;
		}
		$seen[ (string) $entry_id ] = true;

		$meta = $this->fluent_field_map( $form );
		$fields = array();
		foreach ( (array) $form_data as $key => $value ) {
			$key = (string) $key;
			if ( is_array( $value ) ) {
				foreach ( $value as $sub => $sub_value ) {
					$sub_key  = $key . '.' . $sub;
					$info     = $meta[ $sub_key ] ?? $meta[ (string) $sub ] ?? array();
					$fields[] = $this->field( $sub_key, $info['label'] ?? (string) $sub, $info['type'] ?? '', $sub_value );
				}
			}
			$info     = $meta[ $key ] ?? array();
			$fields[] = $this->field( $key, $info['label'] ?? $key, $info['type'] ?? '', $value );
		}
		$form_id = is_object( $form ) ? (string) ( $form->id ?? '' ) : '';
		$title   = is_object( $form ) && ! empty( $form->title ) ? (string) $form->title : 'Fluent Form ' . $form_id;
		$this->handle_submission( 'fluentforms', $form_id, $title, $fields );
	}

	/**
	 * Build a normalised field entry. Array values (checkbox groups, name parts)
	 * are flattened to a space-separated string.
	 */
	private function field( string $id, string $label, string $type, $value ): array {
		if ( is_array( $value ) ) {
			$value = implode( ' ', array_map( static fn( $v ) => is_scalar( $v ) ? (string) $v : '', $value ) );
		} elseif ( ! is_scalar( $value ) ) {
			$value = '';
		}
		return array(
			'id'    => trim( $id ),
			'label' => trim( $label ),
			'type'  => strtolower( trim( $type ) ),
			'value' => trim( (string) $value ),
		);
	}

	/**
	 * WS Form nests fields under groups → sections → fields. Walk defensively.
	 */
	private function wsform_field_map( $form_object ): array {
		$map = array();
		if ( empty( $form_object ) ) {
			return $map;
		}
		foreach ( (array) ( $form_object->groups ?? array() ) as $group ) {
			$sections = is_object( $group ) ? ( $group->sections ?? array() ) : ( $group['sections'] ?? array() );
			foreach ( (array) $sections as $section ) {
				$fields = is_object( $section ) ? ( $section->fields ?? array() ) : ( $section['fields'] ?? array() );
				foreach ( (array) $fields as $field ) {
					$id = is_object( $field ) ? ( $field->id ?? null ) : ( $field['id'] ?? null );
					if ( null === $id ) {
						continue;
					}
					$map[ (string) $id ] = array(
						'label' => (string) ( is_object( $field ) ? ( $field->label ?? '' ) : ( $field['label'] ?? '' ) ),
						'type'  => (string) ( is_object( $field ) ? ( $field->type ?? '' ) : ( $field['type'] ?? '' ) ),
					);
				}
			}
		}
		return $map;
	}

	/**
	 * Fluent Forms stores its field definitions as JSON on the form object.
	 * Returns name → { label, type }, including nested name-field parts.
	 */
	private function fluent_field_map( $form ): array {
		$map = array();
		if ( ! is_object( $form ) || empty( $form->form_fields ) ) {
			return $map;
		}
		$def = is_string( $form->form_fields ) ? json_decode( $form->form_fields, true ) : (array) $form->form_fields;
		if ( ! is_array( $def ) ) {
			return $map;
		}
		$walk = function ( array $items, string $prefix ) use ( &$walk, &$map ) {
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$name = (string) ( $item['attributes']['name'] ?? '' );
				if ( $name ) {
					$map[ $prefix . $name ] = array(
						'label' => (string) ( $item['settings']['label'] ?? ( $item['settings']['admin_field_label'] ?? '' ) ),
						'type'  => (string) ( $item['element'] ?? ( $item['attributes']['type'] ?? '' ) ),
					);
				}
				if ( ! empty( $item['fields'] ) && is_array( $item['fields'] ) ) {
					$walk( array_values( $item['fields'] ), $name ? $name . '.' : $prefix );
				}
				if ( ! empty( $item['columns'] ) && is_array( $item['columns'] ) ) {
					foreach ( $item['columns'] as $col ) {
						if ( ! empty( $col['fields'] ) && is_array( $col['fields'] ) ) {
							$walk( array_values( $col['fields'] ), $prefix );
						}
					}
				}
			}
		};
		$walk( array_values( (array) ( $def['fields'] ?? array() ) ), '' );
		return $map;
	}

	/* =========================================================================
	 * SUBMISSION → PAYLOAD
	 * ======================================================================= */

	private function handle_submission( string $plugin, string $form_id, string $form_title, array $fields, array $extra = array() ): void {
		$contact = $this->extract_contact( $fields, $plugin, $form_id );

		// A submission with neither an email nor a phone number is not a lead.
		if ( empty( $contact['email'] ) && empty( $contact['phone'] ) ) {
			$this->log( sprintf( 'Skipped %s form %s — no email or phone found.', $plugin, $form_id ) );
			return;
		}

		$payload = $this->build_payload( $contact, $plugin, $form_id, $form_title, $extra );
		$result  = $this->dispatch( $payload );

		if ( $result['ok'] ) {
			$this->set_last_lead_cookie( (string) ( $result['lead_id'] ?: '1' ) );
		}
	}

	/**
	 * Resolve name / email / phone / message from normalised fields.
	 *
	 * An explicit mapping row (Settings → Field Mapping) wins for whichever
	 * roles it defines; label/type heuristics fill the rest.
	 */
	public function extract_contact( array $fields, string $plugin = '', string $form_id = '' ): array {
		$out = array( 'name' => '', 'email' => '', 'phone' => '', 'message' => '' );

		$map = $this->find_mapping( $plugin, $form_id );
		if ( $map ) {
			foreach ( array_keys( $out ) as $role ) {
				if ( ! empty( $map[ $role ] ) ) {
					$out[ $role ] = $this->lookup_mapped( $fields, (string) $map[ $role ] );
				}
			}
		}

		// ── Email ────────────────────────────────────────────────────────────
		if ( '' === $out['email'] ) {
			$out['email'] = $this->first_match( $fields, static fn( $f ) => 'email' === $f['type'] && is_email( $f['value'] ) )
				?: $this->first_match( $fields, static fn( $f ) => preg_match( '/\be-?mail\b/i', $f['label'] . ' ' . $f['id'] ) && is_email( $f['value'] ) )
				?: $this->first_match( $fields, static fn( $f ) => ! in_array( $f['type'], array( 'textarea', 'message' ), true ) && is_email( $f['value'] ) );
		}

		// ── Phone ────────────────────────────────────────────────────────────
		if ( '' === $out['phone'] ) {
			$phone_re = '/\b(phone|mobile|tel|telephone|cell|cellphone|contact number|whatsapp)\b|phone_?number|\btel_?no\b/i';
			$out['phone'] = $this->first_match( $fields, static fn( $f ) => in_array( $f['type'], array( 'tel', 'phone', 'phone_number' ), true ) && self::looks_like_phone( $f['value'] ) )
				?: $this->first_match( $fields, static fn( $f ) => preg_match( $phone_re, $f['label'] . ' ' . $f['id'] ) && self::looks_like_phone( $f['value'] ) );
		}

		// ── Name ─────────────────────────────────────────────────────────────
		if ( '' === $out['name'] ) {
			$not_person = '/(company|business|organi[sz]ation|user|file|product|pet|street|website|domain|form|nick|middle|title)/i';

			// A field is a name *part* when it mentions first/last AND is clearly a
			// name field (label says "name", type is "name", or the id ends in
			// ".first"/".last" as WPForms and Gravity Forms sub-inputs do). Plain
			// "First visit" or "Last order" fields don't qualify.
			$name_ctx = static fn( array $f, string $part ): bool => 'name' === $f['type']
				|| str_contains( strtolower( $f['label'] ), 'name' )
				|| str_contains( strtolower( $f['id'] ), 'name' )
				|| (bool) preg_match( '/(^|[._-])' . $part . '$/', strtolower( $f['id'] ) );
			$is_first = static fn( array $f ): bool => (bool) preg_match( '/first|given|fname/i', $f['label'] . ' ' . $f['id'] ) && $name_ctx( $f, 'first' );
			$is_last  = static fn( array $f ): bool => (bool) preg_match( '/last|surname|family|lname/i', $f['label'] . ' ' . $f['id'] ) && $name_ctx( $f, 'last' );

			$full = $this->first_match( $fields, static fn( $f ) => 'name' === $f['type'] && ! $is_first( $f ) && ! $is_last( $f ) );
			if ( '' === $full ) {
				$first = $this->first_match( $fields, $is_first );
				$last  = $this->first_match( $fields, $is_last );
				$full  = trim( $first . ' ' . $last );
			}
			if ( '' === $full ) {
				$full = $this->first_match( $fields, static fn( $f ) => preg_match( '/\bname\b/i', $f['label'] . ' ' . $f['id'] ) && ! preg_match( $not_person, $f['label'] . ' ' . $f['id'] ) && ! is_email( $f['value'] ) );
			}
			$out['name'] = $full;
		}

		// ── Message ──────────────────────────────────────────────────────────
		if ( '' === $out['message'] ) {
			$msg_re = '/(message|comment|enquir|inquir|details|question|notes|how can we help|tell us|describe|project|requirements)/i';
			$out['message'] = $this->first_match( $fields, static fn( $f ) => in_array( $f['type'], array( 'textarea', 'message' ), true ) )
				?: $this->first_match( $fields, static fn( $f ) => preg_match( $msg_re, $f['label'] . ' ' . $f['id'] ) );
		}

		// ── Sanitize ─────────────────────────────────────────────────────────
		$out['name']    = substr( sanitize_text_field( $out['name'] ), 0, 150 );
		$out['email']   = is_email( $out['email'] ) ? strtolower( sanitize_email( $out['email'] ) ) : '';
		$out['phone']   = self::looks_like_phone( $out['phone'] ) ? substr( sanitize_text_field( $out['phone'] ), 0, 40 ) : '';
		$out['message'] = substr( sanitize_textarea_field( $out['message'] ), 0, 2000 );

		return array_filter( $out, static fn( $v ) => '' !== $v );
	}

	private function first_match( array $fields, callable $test ): string {
		foreach ( $fields as $f ) {
			if ( '' !== $f['value'] && $test( $f ) ) {
				return $f['value'];
			}
		}
		return '';
	}

	/**
	 * Resolve a mapping key ("email", "field_12", "First Name|Last Name") to a
	 * value. Keys are matched case-insensitively against the field id and
	 * label; "|" joins several fields with a space.
	 */
	private function lookup_mapped( array $fields, string $spec ): string {
		$parts = array();
		foreach ( explode( '|', $spec ) as $key ) {
			$key = strtolower( trim( $key ) );
			if ( '' === $key ) {
				continue;
			}
			foreach ( $fields as $f ) {
				if ( strtolower( $f['id'] ) === $key || strtolower( $f['label'] ) === $key ) {
					if ( '' !== $f['value'] ) {
						$parts[] = $f['value'];
					}
					break;
				}
			}
		}
		return trim( implode( ' ', $parts ) );
	}

	private function find_mapping( string $plugin, string $form_id ): ?array {
		$rows = (array) $this->settings->get( 'field_map', array() );
		$best = null;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$row_plugin = (string) ( $row['plugin'] ?? 'any' );
			$row_form   = trim( (string) ( $row['form_id'] ?? '' ) );
			if ( 'any' !== $row_plugin && $row_plugin !== $plugin ) {
				continue;
			}
			if ( '' !== $row_form && '*' !== $row_form && $row_form !== $form_id ) {
				continue;
			}
			// Prefer the most specific row: exact form beats wildcard, exact plugin beats any.
			$score = ( '' !== $row_form && '*' !== $row_form ? 2 : 0 ) + ( 'any' !== $row_plugin ? 1 : 0 );
			if ( null === $best || $score > $best['_score'] ) {
				$best = $row + array( '_score' => $score );
			}
		}
		return $best;
	}

	public static function looks_like_phone( string $value ): bool {
		$digits = preg_replace( '/\D+/', '', $value );
		return strlen( $digits ) >= 6 && strlen( $digits ) <= 20 && strlen( $value ) <= 40;
	}

	private function build_payload( array $contact, string $plugin, string $form_id, string $form_title, array $extra ): array {
		$attr  = PPT_Attribution::for_lead();
		$first = $attr['first'];
		$last  = $attr['last'];

		// Top-level source/medium describe the "last non-direct" touch: the
		// session's touch when it has one, otherwise the visitor's first touch.
		$touch = ! empty( $last['source'] ) || ! empty( $last['gclid'] ) || ! empty( $last['fbclid'] ) ? $last : ( $first ?: $last );

		$email = $contact['email'] ?? '';
		$phone = $contact['phone'] ?? '';
		$ip    = (string) apply_filters( 'ppt_client_ip', $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$ua    = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';

		$payload = array(
			'apiKey'             => $this->api_key,
			'name'               => $contact['name'] ?? null,
			'email'              => $email ?: null,
			'phone'              => $phone ?: null,
			'message'            => $contact['message'] ?? null,

			'source'             => $touch['source'] ?? null,
			'medium'             => $touch['medium'] ?? null,
			'campaign'           => $touch['campaign'] ?? null,
			'keyword'            => $touch['term'] ?? null,
			'content'            => $touch['content'] ?? null,
			'channel'            => $touch['channel'] ?? 'Direct',
			'gclid'              => $touch['gclid'] ?? null,
			'fbclid'             => $touch['fbclid'] ?? null,
			'msclkid'            => $touch['msclkid'] ?? null,
			'ttclid'             => $touch['ttclid'] ?? null,
			'liFatId'            => $touch['li_fat_id'] ?? null,
			'dclid'              => $touch['dclid'] ?? null,

			'landingPage'        => PPT_Attribution::site_url_for_path( $first['landing_page'] ?? ( $last['landing_page'] ?? null ) ),
			'sessionLandingPage' => PPT_Attribution::site_url_for_path( $last['landing_page'] ?? null ),
			'pageUrl'            => PPT_Attribution::submitting_page_url(),
			'firstTouch'         => $this->touch_for_payload( $first ),
			'lastTouch'          => $this->touch_for_payload( $last ),
			'firstTouchAt'       => ! empty( $first['timestamp'] ) ? gmdate( 'c', (int) $first['timestamp'] ) : null,

			'visitorId'          => $first['visitor_id'] ?? null,
			'submissionId'       => wp_generate_uuid4(),
			'contactHash'        => ( $email || $phone ) ? hash( 'sha256', strtolower( $email ) . '|' . preg_replace( '/\D+/', '', $phone ) ) : null,
			'ipHash'             => $ip ? substr( hash( 'sha256', $ip . wp_salt( 'auth' ) ), 0, 32 ) : null,
			'userAgent'          => $ua ?: null,

			'formId'             => $form_id ?: null,
			'formTitle'          => $form_title ?: null,
			'formPlugin'         => $plugin,
			'isSpam'             => array_key_exists( 'isSpam', $extra ) ? (bool) $extra['isSpam'] : null,
			'spamScore'          => $extra['spamScore'] ?? null,

			'siteUrl'            => home_url(),
			'pluginVersion'      => PPT_VERSION,
			'submittedAt'        => gmdate( 'c' ),
		);

		/**
		 * Filter the lead payload before it is sent.
		 *
		 * @param array  $payload
		 * @param string $plugin  Form plugin slug.
		 * @param string $form_id
		 */
		$payload = apply_filters( 'ppt_lead_payload', $payload, $plugin, $form_id );

		return array_filter( $payload, static fn( $v ) => null !== $v && '' !== $v && array() !== $v );
	}

	private function touch_for_payload( array $touch ): array {
		if ( empty( $touch ) ) {
			return array();
		}
		$out = array();
		foreach ( array( 'source', 'medium', 'campaign', 'term', 'content', 'channel', 'gclid', 'fbclid', 'msclkid', 'ttclid', 'li_fat_id', 'dclid', 'referrer' ) as $k ) {
			if ( ! empty( $touch[ $k ] ) ) {
				$out[ $k ] = $touch[ $k ];
			}
		}
		if ( ! empty( $touch['landing_page'] ) ) {
			$out['landingPage'] = PPT_Attribution::site_url_for_path( $touch['landing_page'] );
		}
		if ( ! empty( $touch['timestamp'] ) ) {
			$out['at'] = gmdate( 'c', (int) $touch['timestamp'] );
		}
		return $out;
	}

	/* =========================================================================
	 * DELIVERY — blocking send, retry queue, status
	 * ======================================================================= */

	/**
	 * Send a payload now; queue it for retry on a transient failure.
	 *
	 * @return array{ok:bool,code:int,error:string,lead_id:string,queued:bool}
	 */
	public function dispatch( array $payload, bool $allow_queue = true ): array {
		$result           = $this->send( $payload );
		$result['queued'] = false;

		if ( $result['ok'] ) {
			$this->record_success( $result['lead_id'] );
			return $result;
		}

		$this->record_failure( $result['error'] );
		$this->log( 'Lead send failed: ' . $result['error'] );

		if ( $allow_queue && $result['retry'] ) {
			$this->enqueue( $payload, $result['error'] );
			$result['queued'] = true;
		}
		return $result;
	}

	/**
	 * Perform the HTTP request.
	 *
	 * @return array{ok:bool,code:int,error:string,lead_id:string,retry:bool,body:string}
	 */
	private function send( array $payload ): array {
		if ( ! $this->is_configured() ) {
			return array( 'ok' => false, 'code' => 0, 'error' => 'Leads API not configured.', 'lead_id' => '', 'retry' => false, 'body' => '' );
		}

		/**
		 * Filter whether lead delivery blocks the form response. Blocking (default)
		 * lets us record success/failure and retry; non-blocking is fire-and-forget.
		 */
		$blocking = (bool) apply_filters( 'ppt_lead_blocking', true );

		$response = wp_safe_remote_post( $this->endpoint, array(
			'body'       => wp_json_encode( $payload ),
			'headers'    => array(
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'Authorization' => 'Bearer ' . $this->api_key,
				'X-PPT-Version' => PPT_VERSION,
			),
			'timeout'    => (int) apply_filters( 'ppt_lead_timeout', 5 ),
			'blocking'   => $blocking,
			'sslverify'  => true,
			'user-agent' => 'ProgressioPerformanceTracker/' . PPT_VERSION . ' (' . home_url() . ')',
		) );

		if ( ! $blocking ) {
			return array( 'ok' => true, 'code' => 0, 'error' => '', 'lead_id' => '', 'retry' => false, 'body' => '' );
		}

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'code' => 0, 'error' => $response->get_error_message(), 'lead_id' => '', 'retry' => true, 'body' => '' );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( $code >= 200 && $code < 300 ) {
			return array( 'ok' => true, 'code' => $code, 'error' => '', 'lead_id' => $this->lead_id_from_body( $body ), 'retry' => false, 'body' => $body );
		}

		// 4xx (other than rate-limit/timeout) means the request itself is bad —
		// retrying the same payload won't help.
		$retry = $code >= 500 || in_array( $code, array( 408, 425, 429 ), true );
		$error = sprintf( 'HTTP %d: %s', $code, substr( sanitize_text_field( $body ), 0, 200 ) ?: wp_remote_retrieve_response_message( $response ) );

		return array( 'ok' => false, 'code' => $code, 'error' => $error, 'lead_id' => '', 'retry' => $retry, 'body' => $body );
	}

	private function lead_id_from_body( string $body ): string {
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return '';
		}
		foreach ( array( 'leadId', 'lead_id', 'id' ) as $k ) {
			if ( isset( $data[ $k ] ) && is_scalar( $data[ $k ] ) ) {
				return substr( sanitize_text_field( (string) $data[ $k ] ), 0, 64 );
			}
			if ( isset( $data['data'][ $k ] ) && is_scalar( $data['data'][ $k ] ) ) {
				return substr( sanitize_text_field( (string) $data['data'][ $k ] ), 0, 64 );
			}
			if ( isset( $data['lead'][ $k ] ) && is_scalar( $data['lead'][ $k ] ) ) {
				return substr( sanitize_text_field( (string) $data['lead'][ $k ] ), 0, 64 );
			}
		}
		return '';
	}

	/**
	 * Tell tracker.js the Leads API accepted a lead so it can fire GA4's
	 * generate_lead. Value is the dashboard's lead ID, or "1" when the API
	 * returned none. Short-lived, non-HttpOnly by design.
	 */
	private function set_last_lead_cookie( string $lead_id ): void {
		if ( headers_sent() || ! $lead_id ) {
			return;
		}
		setcookie( self::LAST_LEAD_COOKIE, $lead_id, array(
			'expires'  => time() + 300,
			'path'     => '/',
			'secure'   => is_ssl(),
			'httponly' => false,
			'samesite' => 'Lax',
		) );
	}

	// ── Queue ───────────────────────────────────────────────────────────────

	public function get_queue(): array {
		$queue = get_option( PPT_QUEUE_KEY, array() );
		return is_array( $queue ) ? array_values( $queue ) : array();
	}

	public function clear_queue(): void {
		delete_option( PPT_QUEUE_KEY );
		wp_clear_scheduled_hook( PPT_CRON_HOOK );
	}

	private function enqueue( array $payload, string $error ): void {
		$queue   = $this->get_queue();
		$queue[] = array(
			'id'         => $payload['submissionId'] ?? wp_generate_uuid4(),
			'payload'    => $payload,
			'attempts'   => 1,
			'next_at'    => time() + self::BACKOFF[0],
			'last_error' => $error,
			'created'    => time(),
		);
		if ( count( $queue ) > self::MAX_QUEUE ) {
			$queue = array_slice( $queue, - self::MAX_QUEUE );
		}
		update_option( PPT_QUEUE_KEY, $queue, false );
		$this->schedule( self::BACKOFF[0] );
	}

	private function schedule( int $delay ): void {
		if ( ! wp_next_scheduled( PPT_CRON_HOOK ) ) {
			wp_schedule_single_event( time() + $delay, PPT_CRON_HOOK );
		}
	}

	/**
	 * Retry every due item in the queue. Runs from WP-Cron, the settings page
	 * button, and WP-CLI.
	 *
	 * @return array{sent:int,failed:int,dropped:int,remaining:int}
	 */
	public function process_queue( bool $force = false ): array {
		$queue   = $this->get_queue();
		$now     = time();
		$keep    = array();
		$stats   = array( 'sent' => 0, 'failed' => 0, 'dropped' => 0, 'remaining' => 0 );
		$soonest = null;

		foreach ( $queue as $item ) {
			if ( ! $force && ( $item['next_at'] ?? 0 ) > $now ) {
				$keep[]  = $item;
				$soonest = min( $soonest ?? PHP_INT_MAX, (int) $item['next_at'] );
				continue;
			}

			$result = $this->send( (array) $item['payload'] );
			if ( $result['ok'] ) {
				$this->record_success( $result['lead_id'] );
				$stats['sent']++;
				continue;
			}

			$item['attempts']   = (int) ( $item['attempts'] ?? 1 ) + 1;
			$item['last_error'] = $result['error'];
			$this->record_failure( $result['error'] );

			if ( ! $result['retry'] || $item['attempts'] >= self::MAX_ATTEMPTS ) {
				$stats['dropped']++;
				$this->log( sprintf( 'Dropped queued lead %s after %d attempts: %s', $item['id'], $item['attempts'], $result['error'] ) );
				continue;
			}

			$item['next_at'] = $now + self::BACKOFF[ min( $item['attempts'] - 1, count( self::BACKOFF ) - 1 ) ];
			$keep[]          = $item;
			$soonest         = min( $soonest ?? PHP_INT_MAX, $item['next_at'] );
			$stats['failed']++;
		}

		$stats['remaining'] = count( $keep );
		if ( $keep ) {
			update_option( PPT_QUEUE_KEY, $keep, false );
			wp_clear_scheduled_hook( PPT_CRON_HOOK );
			$this->schedule( max( 60, $soonest - $now ) );
		} else {
			delete_option( PPT_QUEUE_KEY );
			wp_clear_scheduled_hook( PPT_CRON_HOOK );
		}

		return $stats;
	}

	// ── Status ──────────────────────────────────────────────────────────────

	public function get_status(): array {
		$status = get_option( PPT_STATUS_KEY, array() );
		return array_merge( array(
			'last_sent_at'  => 0,
			'last_lead_id'  => '',
			'last_error'    => '',
			'last_error_at' => 0,
			'total_sent'    => 0,
			'total_failed'  => 0,
		), is_array( $status ) ? $status : array() );
	}

	private function record_success( string $lead_id ): void {
		$s = $this->get_status();
		$s['last_sent_at'] = time();
		$s['last_lead_id'] = $lead_id;
		$s['total_sent']++;
		update_option( PPT_STATUS_KEY, $s, false );
	}

	private function record_failure( string $error ): void {
		$s = $this->get_status();
		$s['last_error']    = substr( $error, 0, 300 );
		$s['last_error_at'] = time();
		$s['total_failed']++;
		update_option( PPT_STATUS_KEY, $s, false );
	}

	/**
	 * Send a clearly-marked test lead. Never queued.
	 */
	public function send_test( string $email = '' ): array {
		if ( ! $this->is_configured() ) {
			return array( 'ok' => false, 'code' => 0, 'error' => __( 'Leads API key or endpoint is not configured.', 'progressio-performance-tracker' ), 'lead_id' => '', 'queued' => false );
		}
		$email   = is_email( $email ) ? $email : 'test+' . time() . '@example.com';
		$payload = $this->build_payload(
			array( 'name' => 'PPT Test Lead', 'email' => $email, 'phone' => '+1 555 0100', 'message' => 'Test lead sent from the Progressio Performance Tracker settings page.' ),
			'test',
			'ppt-test',
			'Test lead',
			array()
		);
		$payload['test'] = true;
		return $this->dispatch( $payload, false );
	}

	/**
	 * Which supported form plugins are active on this site.
	 *
	 * @return array<string, array{label:string,active:bool}>
	 */
	public static function detect_form_plugins(): array {
		return array(
			'wsform'       => array( 'label' => 'WS Form', 'active' => defined( 'WS_FORM_VERSION' ) || class_exists( 'WS_Form' ) ),
			'gravityforms' => array( 'label' => 'Gravity Forms', 'active' => class_exists( 'GFForms' ) ),
			'wpforms'      => array( 'label' => 'WPForms', 'active' => defined( 'WPFORMS_VERSION' ) ),
			'cf7'          => array( 'label' => 'Contact Form 7', 'active' => defined( 'WPCF7_VERSION' ) ),
			'fluentforms'  => array( 'label' => 'Fluent Forms', 'active' => defined( 'FLUENTFORM' ) || defined( 'FLUENTFORM_VERSION' ) ),
			'formidable'   => array( 'label' => 'Formidable Forms', 'active' => class_exists( 'FrmAppHelper' ) ),
			'ninja'        => array( 'label' => 'Ninja Forms', 'active' => class_exists( 'Ninja_Forms' ) ),
			'elementor'    => array( 'label' => 'Elementor Pro Forms', 'active' => defined( 'ELEMENTOR_PRO_VERSION' ) ),
		);
	}

	private function log( string $message ): void {
		if ( '1' === (string) $this->settings->get( 'debug_mode', '0' ) || ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) ) {
			error_log( '[PPT] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}
}
