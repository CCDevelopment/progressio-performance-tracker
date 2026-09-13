<?php
/**
 * Attribution helpers — reads the first-touch / last-touch cookies written by
 * tracker.js, sanitizes them, and classifies traffic into channels.
 *
 * The cookies are visitor-controlled input. Nothing read here is trusted until
 * it has been coerced to a string, length-capped and sanitized.
 *
 * @package Progressio_Performance_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PPT_Attribution {

	public const COOKIE_FIRST = 'ppt_ft';
	public const COOKIE_LAST  = 'ppt_lt';

	/** Maximum length accepted for any single attribution value. */
	private const MAX_LEN = 200;

	/**
	 * Short cookie key → payload/field name. Keep in sync with tracker.js.
	 */
	private const KEYS = array(
		's'  => 'source',
		'm'  => 'medium',
		'c'  => 'campaign',
		't'  => 'term',
		'n'  => 'content',
		'g'  => 'gclid',
		'f'  => 'fbclid',
		'ms' => 'msclkid',
		'tt' => 'ttclid',
		'li' => 'li_fat_id',
		'dc' => 'dclid',
		'r'  => 'referrer',
		'lp' => 'landing_page',
		'ch' => 'channel',
		'ts' => 'timestamp',
		'v'  => 'visitor_id',
	);

	private const CHANNELS = array(
		'Paid Search',
		'Paid Social',
		'Display',
		'Organic Search',
		'Organic Social',
		'Email',
		'Affiliate',
		'SMS',
		'Referral',
		'Direct',
	);

	/**
	 * Read and sanitize one attribution cookie.
	 *
	 * @param string $name Cookie name (self::COOKIE_FIRST or self::COOKIE_LAST).
	 * @return array Sanitized touch keyed by full field name; empty if absent/invalid.
	 */
	public static function read_touch( string $name ): array {
		if ( empty( $_COOKIE[ $name ] ) || ! is_string( $_COOKIE[ $name ] ) ) {
			return array();
		}

		$raw = wp_unslash( $_COOKIE[ $name ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per-key below.
		if ( strlen( $raw ) > 4096 ) {
			return array();
		}

		$data = json_decode( rawurldecode( $raw ), true );
		if ( ! is_array( $data ) ) {
			return array();
		}

		return self::sanitize_touch( $data );
	}

	/**
	 * Coerce a decoded touch array into a clean, string-only structure.
	 */
	public static function sanitize_touch( array $data ): array {
		$touch = array();

		foreach ( self::KEYS as $short => $full ) {
			$value = $data[ $short ] ?? ( $data[ $full ] ?? null );
			if ( null === $value || is_array( $value ) || is_object( $value ) ) {
				continue;
			}
			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}

			switch ( $full ) {
				case 'landing_page':
					$value = self::sanitize_site_path( $value );
					break;
				case 'timestamp':
					$value = ctype_digit( $value ) ? (int) $value : null;
					break;
				case 'visitor_id':
					$value = preg_match( '/^[A-Za-z0-9\-]{8,64}$/', $value ) ? $value : null;
					break;
				case 'channel':
					$value = in_array( $value, self::CHANNELS, true ) ? $value : null;
					break;
				case 'referrer':
					$value = strtolower( sanitize_text_field( substr( $value, 0, self::MAX_LEN ) ) );
					$value = preg_match( '/^[a-z0-9.\-]+$/', $value ) ? $value : null;
					break;
				default:
					$value = sanitize_text_field( substr( $value, 0, self::MAX_LEN ) );
			}

			if ( null !== $value && '' !== $value ) {
				$touch[ $full ] = $value;
			}
		}

		// Never trust the client's channel label outright — recompute it.
		if ( ! empty( $touch ) ) {
			$touch['channel'] = self::classify( $touch );
		}

		return $touch;
	}

	/**
	 * Classify a touch into a marketing channel. Mirrors classifyChannel() in
	 * tracker.js — keep the two in sync.
	 */
	public static function classify( array $t ): string {
		$src = strtolower( $t['source'] ?? '' );
		$med = strtolower( $t['medium'] ?? '' );
		$ref = strtolower( $t['referrer'] ?? '' );

		$search_re = '/(^|\.)(google|bing|yahoo|duckduckgo|baidu|yandex|ecosia|ask|aol|brave|startpage|qwant)\./';
		$social_re = '/(^|\.)(facebook|instagram|linkedin|twitter|x|t|tiktok|pinterest|youtube|reddit|threads|snapchat|nextdoor|tumblr|quora)\.(com|net|co|be|me)$/';
		$social_src_re = '/^(facebook|fb|ig|instagram|linkedin|twitter|x|tiktok|pinterest|youtube|reddit|threads|snapchat|nextdoor|meta)$/';

		$is_search = (bool) ( preg_match( $search_re, $src . '.' ) || preg_match( $search_re, $ref . '.' ) );
		$is_social = (bool) ( preg_match( $social_src_re, $src ) || preg_match( $social_re, $src ) || preg_match( $social_re, $ref ) );

		if ( ! empty( $t['dclid'] ) ) {
			return 'Display';
		}
		if ( ! empty( $t['gclid'] ) || ! empty( $t['msclkid'] ) ) {
			return $is_social ? 'Paid Social' : 'Paid Search';
		}
		if ( ! empty( $t['fbclid'] ) || ! empty( $t['ttclid'] ) || ! empty( $t['li_fat_id'] ) ) {
			return 'Paid Social';
		}

		if ( in_array( $med, array( 'paid_social', 'paidsocial', 'paid-social', 'social_paid', 'social-paid' ), true ) ) {
			return 'Paid Social';
		}
		if ( in_array( $med, array( 'cpc', 'ppc', 'paidsearch', 'paid_search', 'paid-search', 'paid', 'sem', 'cpv', 'cpa', 'retargeting' ), true ) ) {
			return $is_social ? 'Paid Social' : 'Paid Search';
		}
		if ( in_array( $med, array( 'display', 'banner', 'cpm', 'expandable', 'interstitial' ), true ) ) {
			return 'Display';
		}
		if ( 'organic' === $med ) {
			return 'Organic Search';
		}
		if ( in_array( $med, array( 'social', 'social-network', 'social-media', 'social_network', 'social_media', 'sm' ), true ) ) {
			return 'Organic Social';
		}
		if ( in_array( $med, array( 'email', 'e-mail', 'e_mail', 'e mail', 'newsletter' ), true ) ) {
			return 'Email';
		}
		if ( 'affiliate' === $med ) {
			return 'Affiliate';
		}
		if ( 'sms' === $med ) {
			return 'SMS';
		}

		// No recognised medium — fall back to what the source/referrer looks like.
		if ( $is_search ) {
			return 'Organic Search';
		}
		if ( $is_social ) {
			return 'Organic Social';
		}
		if ( '' !== $src || '' !== $ref ) {
			return 'Referral';
		}
		return 'Direct';
	}

	/**
	 * Keep only the path and a whitelist of query parameters from a URL or
	 * path, so sensitive query strings never reach the Leads API.
	 */
	public static function sanitize_site_path( string $value ): ?string {
		$value = substr( $value, 0, 2000 );
		$parts = wp_parse_url( $value );
		if ( false === $parts ) {
			return null;
		}

		$path = $parts['path'] ?? '/';
		$path = '/' . ltrim( sanitize_text_field( $path ), '/' );

		$keep = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
			foreach ( (array) $query as $k => $v ) {
				$k = (string) $k;
				if ( is_array( $v ) ) {
					continue;
				}
				if ( str_starts_with( $k, 'utm_' ) || in_array( $k, array( 'gclid', 'fbclid', 'msclkid', 'ttclid', 'li_fat_id', 'dclid' ), true ) ) {
					$keep[ $k ] = sanitize_text_field( substr( (string) $v, 0, self::MAX_LEN ) );
				}
			}
		}

		return $keep ? $path . '?' . http_build_query( $keep ) : $path;
	}

	/**
	 * Absolute URL on this site for a sanitized path.
	 */
	public static function site_url_for_path( ?string $path ): ?string {
		if ( ! $path ) {
			return null;
		}
		return home_url( $path );
	}

	/**
	 * The page the form was submitted from, taken from the HTTP referer and
	 * accepted only when it points at this site.
	 */
	public static function submitting_page_url(): ?string {
		$ref = wp_get_referer();
		if ( ! $ref ) {
			$ref = isset( $_SERVER['HTTP_REFERER'] ) ? wp_unslash( $_SERVER['HTTP_REFERER'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		if ( ! $ref || ! is_string( $ref ) ) {
			return null;
		}

		$ref_host  = strtolower( (string) wp_parse_url( $ref, PHP_URL_HOST ) );
		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( ! $ref_host || self::strip_www( $ref_host ) !== self::strip_www( $site_host ) ) {
			return null;
		}

		return self::site_url_for_path( self::sanitize_site_path( $ref ) );
	}

	public static function strip_www( string $host ): string {
		return preg_replace( '/^www\./', '', $host );
	}

	/**
	 * Full attribution bundle for a lead payload: last touch (top-level, for
	 * backwards compatibility with the dashboard) plus first touch nested.
	 */
	public static function for_lead(): array {
		$first = self::read_touch( self::COOKIE_FIRST );
		$last  = self::read_touch( self::COOKIE_LAST );

		// A visitor with only a first-touch cookie (new session, no new touch)
		// is a returning direct visit; the last touch is then "Direct".
		if ( empty( $last ) ) {
			$last = array( 'channel' => 'Direct' );
		}

		return array(
			'first' => $first,
			'last'  => $last,
		);
	}
}
