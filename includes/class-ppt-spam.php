<?php
/**
 * Spam filter for Progressio Performance Tracker.
 *
 * Blocks the SEO / search-ad / AI-search pitches that reach client contact
 * forms. Rules are literal strings of four kinds (phrases, sender domains,
 * email addresses, phone numbers). A global list is pulled from the Progressio
 * dashboard and cached; each site can add its own entries and exempt global
 * ones. The same evaluator runs twice per submission:
 *
 *   1. In every supported form plugin's validation hook, so a matching
 *      submission is rejected before the plugin emails the client.
 *   2. Inside PPT_Leads::handle_submission() as a fallback flag, so anything
 *      that slipped past validation is still marked isSpam on the dashboard.
 *
 * A rejected submission is still POSTed to the Leads API with isSpam: true,
 * spamReason and blocked: true, so the dashboard holds a quarantine the global
 * list can be tuned from. It never sets the generate_lead cookie.
 *
 * @package Progressio_Performance_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PPT_Spam {

	private static ?PPT_Spam $instance = null;
	private PPT_Settings $settings;

	/** Rule types, in the order they are evaluated. */
	public const TYPES = array( 'phrases', 'domains', 'emails', 'phones' );

	private const MAX_RULES_PER_TYPE = 1000;
	private const MAX_RULE_LENGTH    = 200;

	/** How long a fetched global list is considered fresh. */
	private const FRESH_FOR = 6 * HOUR_IN_SECONDS;

	/** Per-request cache of the effective rule set. */
	private ?array $effective = null;

	public static function get_instance(): PPT_Spam {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings = PPT_Settings::get_instance();

		add_action( PPT_SPAM_CRON_HOOK, array( $this, 'refresh' ) );

		if ( $this->is_enabled() ) {
			$this->hook_forms();
			if ( ! wp_next_scheduled( PPT_SPAM_CRON_HOOK ) && $this->rules_endpoint() ) {
				wp_schedule_event( time() + MINUTE_IN_SECONDS, 'twicedaily', PPT_SPAM_CRON_HOOK );
			}
		} elseif ( wp_next_scheduled( PPT_SPAM_CRON_HOOK ) ) {
			wp_clear_scheduled_hook( PPT_SPAM_CRON_HOOK );
		}
	}

	public function is_enabled(): bool {
		return '1' === (string) $this->settings->get( 'spam_filter', '0' );
	}

	/**
	 * Message shown to the submitter. Deliberately generic so a spammer learns
	 * nothing about which rule matched.
	 */
	public static function block_message(): string {
		/**
		 * Filter the validation message shown when a submission is blocked.
		 *
		 * @param string $message
		 */
		return (string) apply_filters( 'ppt_spam_block_message', __( 'Sorry, this message could not be sent.', 'progressio-performance-tracker' ) );
	}

	/* =========================================================================
	 * RULES: parsing, normalising, merging
	 * ======================================================================= */

	/** An empty, fully-keyed rule set. */
	public static function empty_rules(): array {
		return array_fill_keys( self::TYPES, array() );
	}

	/**
	 * Normalise one rule of a known type. Returns '' when it cannot be a rule.
	 */
	public static function normalize( string $type, string $rule ): string {
		$rule = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $rule ) ) );
		if ( '' === $rule || strlen( $rule ) > self::MAX_RULE_LENGTH ) {
			return '';
		}
		switch ( $type ) {
			case 'phones':
				$digits = preg_replace( '/\D+/', '', $rule );
				if ( 11 === strlen( $digits ) && '1' === $digits[0] ) {
					$digits = substr( $digits, 1 );
				}
				return strlen( $digits ) >= 7 && strlen( $digits ) <= 15 ? $digits : '';
			case 'domains':
				$rule = strtolower( preg_replace( '~^[a-z]+://~i', '', $rule ) );
				$rule = preg_replace( '~^www\.~', '', explode( '/', $rule )[0] );
				return preg_match( '/^(?:[a-z0-9-]+\.)+[a-z]{2,}$/', $rule ) ? $rule : '';
			case 'emails':
				$rule = strtolower( $rule );
				return is_email( $rule ) ? $rule : '';
			case 'phrases':
			default:
				return function_exists( 'mb_strtolower' ) ? mb_strtolower( $rule ) : strtolower( $rule );
		}
	}

	/**
	 * Work out which kind of rule a bare line is. Explicit "type: value"
	 * prefixes win; otherwise an email, a bare domain, or a phone-shaped string
	 * is recognised and everything else is a phrase.
	 *
	 * @return array{0:string,1:string} [ type, normalised rule ]; type is '' when unusable.
	 */
	public static function classify_line( string $line ): array {
		$line = trim( $line );
		if ( '' === $line || '#' === $line[0] ) {
			return array( '', '' );
		}
		if ( preg_match( '/^(phrase|domain|email|phone)s?\s*:\s*(.+)$/i', $line, $m ) ) {
			$type = strtolower( $m[1] ) . 's';
			return array( $type, self::normalize( $type, $m[2] ) );
		}
		if ( str_contains( $line, '@' ) && is_email( strtolower( $line ) ) ) {
			return array( 'emails', self::normalize( 'emails', $line ) );
		}
		if ( ! str_contains( $line, ' ' ) && '' !== self::normalize( 'domains', $line ) ) {
			return array( 'domains', self::normalize( 'domains', $line ) );
		}
		if ( preg_match( '/^\+?[\d\s().-]{7,}$/', $line ) && '' !== self::normalize( 'phones', $line ) ) {
			return array( 'phones', self::normalize( 'phones', $line ) );
		}
		return array( 'phrases', self::normalize( 'phrases', $line ) );
	}

	/**
	 * Parse a one-rule-per-line textarea into a typed rule set.
	 */
	public static function parse_lines( string $text ): array {
		$rules = self::empty_rules();
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			[ $type, $rule ] = self::classify_line( (string) $line );
			if ( '' !== $type && '' !== $rule ) {
				$rules[ $type ][] = $rule;
			}
		}
		return self::tidy( $rules );
	}

	/**
	 * Accept a decoded dashboard response (or any loosely-shaped array) and
	 * return a typed rule set. Tolerates wrappers (`data`, `rules`), singular
	 * keys and `keywords` as an alias for phrases.
	 */
	public static function from_array( $data ): array {
		$rules = self::empty_rules();
		if ( ! is_array( $data ) ) {
			return $rules;
		}
		foreach ( array( 'data', 'rules' ) as $wrap ) {
			if ( isset( $data[ $wrap ] ) && is_array( $data[ $wrap ] ) ) {
				$data = $data[ $wrap ];
			}
		}
		$aliases = array(
			'phrases' => array( 'phrases', 'phrase', 'keywords', 'keyword' ),
			'domains' => array( 'domains', 'domain' ),
			'emails'  => array( 'emails', 'email' ),
			'phones'  => array( 'phones', 'phone', 'phone_numbers', 'phoneNumbers' ),
		);
		foreach ( $aliases as $type => $keys ) {
			foreach ( $keys as $key ) {
				if ( ! isset( $data[ $key ] ) ) {
					continue;
				}
				$list = $data[ $key ];
				if ( is_string( $list ) ) {
					$list = preg_split( '/\r\n|\r|\n|,/', $list );
				}
				foreach ( (array) $list as $item ) {
					if ( is_array( $item ) ) {
						$item = $item['value'] ?? ( $item['rule'] ?? '' );
					}
					if ( is_scalar( $item ) ) {
						$rules[ $type ][] = self::normalize( $type, (string) $item );
					}
				}
			}
		}
		return self::tidy( $rules );
	}

	/** Drop blanks and duplicates, cap list sizes, keep every type keyed. */
	private static function tidy( array $rules ): array {
		$out = self::empty_rules();
		foreach ( self::TYPES as $type ) {
			$list = array_filter( array_map( 'strval', (array) ( $rules[ $type ] ?? array() ) ), static fn( $r ) => '' !== $r );
			$out[ $type ] = array_slice( array_values( array_unique( $list ) ), 0, self::MAX_RULES_PER_TYPE );
		}
		return $out;
	}

	/** Total number of rules across all types. */
	public static function count_rules( array $rules ): int {
		return array_sum( array_map( 'count', array_intersect_key( $rules, self::empty_rules() ) ) );
	}

	/**
	 * The rule set this site enforces: global rules plus the site's additions,
	 * minus the site's exceptions.
	 */
	public function effective_rules(): array {
		if ( null !== $this->effective ) {
			return $this->effective;
		}
		$global = $this->global_rules();
		$add    = self::parse_lines( (string) $this->settings->get( 'spam_add', '' ) );
		$allow  = self::parse_lines( (string) $this->settings->get( 'spam_allow', '' ) );

		$rules = self::empty_rules();
		foreach ( self::TYPES as $type ) {
			$rules[ $type ] = array_values( array_diff( array_unique( array_merge( $global[ $type ], $add[ $type ] ) ), $allow[ $type ] ) );
		}

		/**
		 * Filter the effective spam rules for this site.
		 *
		 * @param array $rules  { phrases: string[], domains: string[], emails: string[], phones: string[] }
		 */
		$this->effective = self::tidy( (array) apply_filters( 'ppt_spam_rules', $rules ) );
		return $this->effective;
	}

	/** The cached global list (last good copy from the dashboard). */
	public function global_rules(): array {
		$state = $this->state();
		return self::from_array( $state['rules'] ?? array() );
	}

	/* =========================================================================
	 * EVALUATION
	 * ======================================================================= */

	/**
	 * Test a submission's fields against a rule set.
	 *
	 * @param array      $fields Normalised fields: [ { id, label, type, value } ].
	 * @param array|null $rules  Defaults to effective_rules().
	 * @return array|null { type, rule, field } for the first match, or null.
	 */
	public function evaluate( array $fields, ?array $rules = null ): ?array {
		$rules = $rules ?? $this->effective_rules();
		if ( 0 === self::count_rules( $rules ) ) {
			return null;
		}
		foreach ( $fields as $f ) {
			$value = is_array( $f ) ? (string) ( $f['value'] ?? '' ) : (string) $f;
			if ( '' === trim( $value ) ) {
				continue;
			}
			$hit = self::match_value( $value, $rules );
			if ( $hit ) {
				$hit['field'] = is_array( $f ) ? (string) ( $f['label'] ?: ( $f['id'] ?? '' ) ) : '';
				return $hit;
			}
		}
		return null;
	}

	/**
	 * Match a single string against every rule type.
	 *
	 * @return array|null { type, rule }
	 */
	public static function match_value( string $value, array $rules ): ?array {
		$value = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );

		foreach ( $rules['phrases'] ?? array() as $phrase ) {
			if ( '' !== $phrase && preg_match( self::phrase_regex( $phrase ), $value ) ) {
				return array( 'type' => 'phrases', 'rule' => $phrase );
			}
		}

		foreach ( $rules['emails'] ?? array() as $email ) {
			if ( '' !== $email && preg_match( '/(?<![a-z0-9._%+-])' . preg_quote( $email, '/' ) . '(?![a-z0-9-])/i', $value ) ) {
				return array( 'type' => 'emails', 'rule' => $email );
			}
		}

		foreach ( $rules['domains'] ?? array() as $domain ) {
			// The domain itself or any subdomain, anywhere in the text: an email
			// address, a pasted URL, or just mentioned.
			if ( '' !== $domain && preg_match( '/(?<![a-z0-9-])(?:[a-z0-9-]+\.)*' . preg_quote( $domain, '/' ) . '(?![a-z0-9-])/i', $value ) ) {
				return array( 'type' => 'domains', 'rule' => $domain );
			}
		}

		if ( ! empty( $rules['phones'] ) ) {
			foreach ( self::phone_candidates( $value ) as $digits ) {
				if ( in_array( $digits, $rules['phones'], true ) ) {
					return array( 'type' => 'phones', 'rule' => $digits );
				}
			}
		}

		return null;
	}

	/**
	 * Whole-word, case-insensitive. Spaces and hyphens inside the phrase match
	 * any run of whitespace or hyphens, so "no-obligation audit" also catches
	 * "no obligation  audit".
	 */
	private static function phrase_regex( string $phrase ): string {
		$parts = preg_split( '/[\s-]+/u', $phrase );
		$body  = implode( '[\s\-]+', array_map( static fn( $p ) => preg_quote( $p, '/' ), $parts ) );
		return '/(?<![\p{L}\p{N}])' . $body . '(?![\p{L}\p{N}])/iu';
	}

	/**
	 * Every phone-shaped run of digits in a string, normalised the way rules
	 * are, so "(805) 400-2077", "+1 805 400 2077" and "805.400.2077" all
	 * compare equal. Catches numbers buried in a message as well as the phone
	 * field itself.
	 *
	 * @return string[]
	 */
	public static function phone_candidates( string $value ): array {
		$out = array();
		if ( preg_match_all( '/\+?\d(?:[\d\s().\-]*\d)?/', $value, $m ) ) {
			foreach ( $m[0] as $run ) {
				$digits = self::normalize( 'phones', $run );
				if ( '' !== $digits ) {
					$out[] = $digits;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/* =========================================================================
	 * GLOBAL LIST: fetched from the dashboard, cached in an option
	 * ======================================================================= */

	/**
	 * Where the global list lives. Derived from the Leads endpoint
	 * (…/api/leads → …/api/spam-rules) unless overridden.
	 */
	public function rules_endpoint(): string {
		if ( defined( 'PPT_SPAM_RULES_ENDPOINT' ) && PPT_SPAM_RULES_ENDPOINT ) {
			$url = (string) PPT_SPAM_RULES_ENDPOINT;
		} else {
			$leads = (string) $this->settings->get( 'leads_endpoint', PPT_DEFAULT_ENDPOINT );
			$url   = preg_match( '~/leads/?$~', $leads ) ? preg_replace( '~/leads/?$~', '/spam-rules', $leads ) : rtrim( $leads, '/' ) . '/spam-rules';
		}
		/**
		 * Filter the URL the global spam rules are fetched from.
		 *
		 * @param string $url
		 */
		$url = (string) apply_filters( 'ppt_spam_rules_endpoint', $url );
		return PPT_Settings::is_valid_endpoint( $url ) ? $url : '';
	}

	/**
	 * Cached fetch state.
	 *
	 * @return array{rules:array,fetched_at:int,updated_at:string,error:string,error_at:int}
	 */
	public function state(): array {
		$state = get_option( PPT_SPAM_RULES_KEY, array() );
		return array_merge(
			array( 'rules' => array(), 'fetched_at' => 0, 'updated_at' => '', 'error' => '', 'error_at' => 0 ),
			is_array( $state ) ? $state : array()
		);
	}

	public function is_stale(): bool {
		return ( time() - (int) $this->state()['fetched_at'] ) > self::FRESH_FOR;
	}

	/**
	 * Pull the global list from the dashboard. Keeps the last good copy on any
	 * failure. Never called during a visitor's form submission.
	 *
	 * @return array{ok:bool,count:int,error:string}
	 */
	public function refresh(): array {
		$url = $this->rules_endpoint();
		$key = (string) $this->settings->get( 'leads_api_key', '' );
		if ( '' === $url || '' === $key ) {
			return array( 'ok' => false, 'count' => 0, 'error' => __( 'Leads API key or endpoint is not configured.', 'progressio-performance-tracker' ) );
		}

		$response = wp_safe_remote_get( $url, array(
			'headers'    => array(
				'Accept'        => 'application/json',
				'Authorization' => 'Bearer ' . $key,
				'X-PPT-Version' => PPT_VERSION,
			),
			'timeout'    => (int) apply_filters( 'ppt_spam_rules_timeout', 8 ),
			'sslverify'  => true,
			'user-agent' => 'ProgressioPerformanceTracker/' . PPT_VERSION . ' (' . home_url() . ')',
		) );

		$state = $this->state();

		if ( is_wp_error( $response ) ) {
			return $this->record_fetch_error( $state, $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			return $this->record_fetch_error( $state, sprintf( 'HTTP %d: %s', $code, substr( sanitize_text_field( $body ), 0, 200 ) ) );
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return $this->record_fetch_error( $state, __( 'Response was not JSON.', 'progressio-performance-tracker' ) );
		}

		$rules = self::from_array( $data );
		$inner = $data;
		foreach ( array( 'data', 'rules' ) as $wrap ) {
			if ( isset( $inner[ $wrap ] ) && is_array( $inner[ $wrap ] ) ) {
				$inner = $inner[ $wrap ];
			}
		}
		$updated = (string) ( $data['updatedAt'] ?? ( $data['updated_at'] ?? ( $inner['updatedAt'] ?? ( $inner['updated_at'] ?? '' ) ) ) );

		update_option( PPT_SPAM_RULES_KEY, array(
			'rules'      => $rules,
			'fetched_at' => time(),
			'updated_at' => substr( sanitize_text_field( $updated ), 0, 40 ),
			'error'      => '',
			'error_at'   => 0,
		), false );
		$this->effective = null;

		return array( 'ok' => true, 'count' => self::count_rules( $rules ), 'error' => '' );
	}

	private function record_fetch_error( array $state, string $error ): array {
		$state['error']    = substr( $error, 0, 300 );
		$state['error_at'] = time();
		update_option( PPT_SPAM_RULES_KEY, $state, false );
		return array( 'ok' => false, 'count' => self::count_rules( self::from_array( $state['rules'] ) ), 'error' => $error );
	}

	/* =========================================================================
	 * FORM PLUGIN VALIDATION HOOKS: reject before the client is emailed
	 * ======================================================================= */

	private function hook_forms(): void {
		$plugin = (string) $this->settings->get( 'form_plugin', 'auto' );
		$auto   = ( 'auto' === $plugin );
		$want   = static fn( string $slug ) => $auto || $plugin === $slug;
		$leads  = PPT_Leads::get_instance();

		// ── WS Form ───────────────────────────────────────────────────────────
		// Form-level hook: every field is in $submit->meta by now. Appending a
		// string to the error list fails validation with that message.
		if ( $want( 'wsform' ) ) {
			add_filter( 'wsf_submit_validate', function ( $errors, $post_mode, $submit ) use ( $leads ) {
				if ( 'submit' !== $post_mode || ! is_object( $submit ) ) {
					return $errors;
				}
				$n = $leads->normalise_wsform( $submit );
				if ( $n && $this->block( 'wsform', $n ) ) {
					$errors   = is_array( $errors ) ? $errors : array();
					$errors[] = self::block_message();
				}
				return $errors;
			}, 10, 3 );
		}

		// ── Gravity Forms ─────────────────────────────────────────────────────
		if ( $want( 'gravityforms' ) ) {
			add_filter( 'gform_validation', function ( $result ) use ( $leads ) {
				$form = $result['form'] ?? null;
				if ( ! is_array( $form ) || ! class_exists( 'GFFormsModel' ) || ! method_exists( 'GFFormsModel', 'get_current_lead' ) ) {
					return $result;
				}
				$entry = GFFormsModel::get_current_lead();
				$n     = $leads->normalise_gravityforms( $entry, $form );
				$hit   = $n ? $this->block( 'gravityforms', $n ) : null;
				if ( ! $hit ) {
					return $result;
				}
				$result['is_valid'] = false;
				// GF only shows a message when a field is marked as failed.
				$marked = false;
				foreach ( $form['fields'] as $field ) {
					if ( is_object( $field ) && ! $marked && ( (string) $field->label === $hit['field'] || (string) $field->id === $hit['field'] ) ) {
						$field->failed_validation  = true;
						$field->validation_message = self::block_message();
						$marked                    = true;
					}
				}
				if ( ! $marked ) {
					foreach ( $form['fields'] as $field ) {
						if ( is_object( $field ) && ! in_array( (string) ( $field->type ?? '' ), array( 'hidden', 'honeypot', 'captcha', 'html', 'section', 'page' ), true ) ) {
							$field->failed_validation  = true;
							$field->validation_message = self::block_message();
							break;
						}
					}
				}
				$result['form'] = $form;
				return $result;
			}, 10, 1 );
		}

		// ── WPForms ───────────────────────────────────────────────────────────
		if ( $want( 'wpforms' ) ) {
			add_action( 'wpforms_process', function ( $fields, $entry, $form_data ) use ( $leads ) {
				$n = $leads->normalise_wpforms( (array) $fields, (array) $form_data );
				if ( $n && $this->block( 'wpforms', $n ) && function_exists( 'wpforms' ) ) {
					$form_id = (string) ( $form_data['id'] ?? '' );
					wpforms()->process->errors[ $form_id ]['header'] = self::block_message();
				}
			}, 10, 3 );
		}

		// ── Contact Form 7 ────────────────────────────────────────────────────
		// Returning true marks the submission as spam: no mail, and the form
		// shows its spam message. wpcf7_mail_sent then never fires for it.
		if ( $want( 'cf7' ) ) {
			add_filter( 'wpcf7_spam', function ( $spam, $submission = null ) use ( $leads ) {
				if ( $spam || ! is_object( $submission ) || ! method_exists( $submission, 'get_contact_form' ) ) {
					return $spam;
				}
				$n = $leads->normalise_cf7( $submission->get_contact_form(), $submission );
				return $n && $this->block( 'cf7', $n ) ? true : $spam;
			}, 10, 2 );
		}

		// ── Fluent Forms ──────────────────────────────────────────────────────
		if ( $want( 'fluentforms' ) ) {
			$cb = function ( $errors, $form_data, $form ) use ( $leads ) {
				$n = $leads->normalise_fluentforms( (array) $form_data, $form );
				if ( $n && $this->block( 'fluentforms', $n ) ) {
					$errors = is_array( $errors ) ? $errors : array();
					// Keyed by field name; the first text field carries the message.
					$name            = $n['fields'][0]['id'] ?? 'form';
					$errors[ $name ] = array( self::block_message() );
				}
				return $errors;
			};
			add_filter( 'fluentform/validation_errors', $cb, 10, 3 );
			add_filter( 'fluentform_validation_errors', $cb, 10, 3 );
		}

		// ── Formidable Forms ──────────────────────────────────────────────────
		if ( $want( 'formidable' ) ) {
			add_filter( 'frm_validate_entry', function ( $errors, $values ) use ( $leads ) {
				$n = $leads->normalise_formidable_values( (array) $values );
				if ( $n && $this->block( 'formidable', $n ) ) {
					$errors = is_array( $errors ) ? $errors : array();
					// Same key Formidable's own Akismet check uses; shown at the top of the form.
					$errors['spam'] = self::block_message();
				}
				return $errors;
			}, 10, 2 );
		}

		// ── Elementor Pro Forms ───────────────────────────────────────────────
		if ( $want( 'elementor' ) ) {
			add_action( 'elementor_pro/forms/validation', function ( $record, $ajax_handler ) use ( $leads ) {
				$n = $leads->normalise_elementor( $record );
				if ( $n && $this->block( 'elementor', $n ) && is_object( $ajax_handler ) && method_exists( $ajax_handler, 'add_error_message' ) ) {
					$ajax_handler->add_error_message( self::block_message() );
				}
			}, 10, 2 );
		}

		// ── Ninja Forms ───────────────────────────────────────────────────────
		if ( $want( 'ninja' ) ) {
			add_filter( 'ninja_forms_submit_data', function ( $form_data ) use ( $leads ) {
				$n = $leads->normalise_ninja_submit_data( (array) $form_data );
				if ( $n && $this->block( 'ninja', $n ) ) {
					$form_data['errors']['form']['ppt_spam'] = self::block_message();
				}
				return $form_data;
			}, 10, 1 );
		}

		// ── Bricks native forms ───────────────────────────────────────────────
		// Runs before Bricks' own required/email checks; a non-empty error list
		// stops every form action (email, submission storage, redirects).
		if ( $want( 'bricks' ) ) {
			add_filter( 'bricks/form/validate', function ( $errors, $form ) use ( $leads ) {
				$n = $leads->normalise_bricks( $form );
				if ( $n && $this->block( 'bricks', $n ) ) {
					$errors   = is_array( $errors ) ? $errors : array();
					$errors[] = self::block_message();
				}
				return $errors;
			}, 10, 2 );
		}
	}

	/**
	 * Evaluate a normalised submission; on a match, report it to the dashboard
	 * as blocked spam and return the hit.
	 *
	 * @param array{fields:array,form_id:string,title:string} $n
	 */
	private function block( string $plugin, array $n ): ?array {
		// Some plugins fire their validation filter twice per submission (Fluent
		// Forms runs both its current and its deprecated hook name). Report each
		// submission once per request.
		static $seen = array();
		$key = $plugin . '|' . (string) $n['form_id'] . '|' . md5( (string) wp_json_encode( $n['fields'] ) );
		if ( array_key_exists( $key, $seen ) ) {
			return $seen[ $key ];
		}
		$hit          = $this->evaluate( $n['fields'] );
		$seen[ $key ] = $hit;
		if ( ! $hit ) {
			return null;
		}
		/**
		 * Fires when a form submission is rejected by the spam filter.
		 *
		 * @param array  $hit    { type, rule, field }
		 * @param string $plugin Form plugin slug.
		 * @param array  $n      { fields, form_id, title }
		 */
		do_action( 'ppt_spam_blocked', $hit, $plugin, $n );
		PPT_Leads::get_instance()->report_blocked( $plugin, $n['form_id'], $n['title'], $n['fields'], $hit );
		return $hit;
	}

	/* =========================================================================
	 * STATUS
	 * ======================================================================= */

	/** Short description of the rule set for the status panel and CLI. */
	public function summary(): array {
		$state  = $this->state();
		$global = self::from_array( $state['rules'] );
		$add    = self::parse_lines( (string) $this->settings->get( 'spam_add', '' ) );
		$allow  = self::parse_lines( (string) $this->settings->get( 'spam_allow', '' ) );
		return array(
			'enabled'     => $this->is_enabled(),
			'endpoint'    => $this->rules_endpoint(),
			'global'      => self::count_rules( $global ),
			'additions'   => self::count_rules( $add ),
			'exceptions'  => self::count_rules( $allow ),
			'effective'   => self::count_rules( $this->effective_rules() ),
			'fetched_at'  => (int) $state['fetched_at'],
			'updated_at'  => (string) $state['updated_at'],
			'error'       => (string) $state['error'],
			'error_at'    => (int) $state['error_at'],
		);
	}
}
