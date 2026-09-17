<?php
/**
 * Expose the active SEO plugin's meta to the REST API.
 *
 * Rank Math stores SEO data as plain post meta and does not register it for
 * REST writes. WordPress silently drops unregistered meta on a REST write: the
 * post saves with a 201 and the SEO fields stay empty, with nothing in the
 * response to say so. Registering the keys here lets the Progressio Dash
 * content pipeline set SEO meta when it publishes over /wp/v2/posts — no
 * custom endpoint required.
 *
 * Yoast is different in practice. Verified against Yoast SEO 28.5 on two live
 * sites: it already registers _yoast_wpseo_title, _yoast_wpseo_metadesc and
 * _yoast_wpseo_focuskw with show_in_rest and its own sanitize/auth callbacks,
 * so those keys are already writable over REST. We therefore skip any key that
 * is already registered for REST rather than re-registering it — a second
 * register_post_meta() call for the same key wins, which would silently
 * replace the owning plugin's sanitizer (WPSEO_Meta::sanitize_post_meta) with
 * ours. Older Yoast builds that lack the registration are still covered.
 *
 * Only the keys belonging to the SEO plugin actually active on the site are
 * considered; registering the other set would create orphan meta that nothing
 * reads. When neither plugin is active nothing is registered and nothing errors.
 *
 * Writes are gated on edit_post so an authenticated low-privilege user cannot
 * rewrite SEO meta on someone else's post. For the Yoast keys, which are
 * protected meta (leading underscore), that callback is also what makes them
 * writable at all.
 *
 * Scope is deliberately limited to single-line string fields. Robots
 * directives, schema and social overrides are array or serialized values and
 * are left alone.
 *
 * @package Progressio_Performance_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PPT_SEO_Meta {

	/**
	 * Post types the meta is registered for. The content pipeline publishes
	 * posts only; filter 'ppt_seo_meta_post_types' to widen it.
	 */
	private const POST_TYPES = array( 'post' );

	/**
	 * String meta keys per SEO plugin, mapped to the REST schema description.
	 * Anything non-string is intentionally out of scope.
	 */
	private const KEYS = array(
		'rank_math' => array(
			'rank_math_title'         => 'SEO title (Rank Math).',
			'rank_math_description'   => 'Meta description (Rank Math).',
			'rank_math_focus_keyword' => 'Focus keyword (Rank Math).',
		),
		'yoast'     => array(
			'_yoast_wpseo_title'    => 'SEO title (Yoast).',
			'_yoast_wpseo_metadesc' => 'Meta description (Yoast).',
			'_yoast_wpseo_focuskw'  => 'Focus keyword (Yoast).',
		),
	);

	/**
	 * Hook registration. Meta must be registered on init so both the REST API
	 * and the block editor see the keys. The late priority matters: it lets the
	 * SEO plugin register its own keys first, so register() can tell which ones
	 * are genuinely missing.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ), 99 );
	}

	/**
	 * Which supported SEO plugins are active, as a list of slugs.
	 *
	 * Both plugins define their version constant at file load, well before
	 * init, so the constant is the primary signal; the class check is a
	 * fallback for builds that rename or drop the constant.
	 *
	 * @return string[] Any of 'rank_math', 'yoast'.
	 */
	public static function detect(): array {
		$active = array();

		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			$active[] = 'rank_math';
		}

		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) {
			$active[] = 'yoast';
		}

		return $active;
	}

	/**
	 * Detected SEO plugin as a single reportable value for the leads payload.
	 *
	 * Returns 'rank_math', 'yoast', 'none', or — if a site somehow runs both —
	 * 'rank_math+yoast'. Consumers should treat the value as an opaque label
	 * and test with a substring match rather than strict equality.
	 */
	public static function detected_label(): string {
		$active = self::detect();

		return empty( $active ) ? 'none' : implode( '+', $active );
	}

	/**
	 * True when something has already registered this key for REST on this post
	 * type — in which case it is writable and we must leave it alone.
	 */
	private static function already_exposed( string $post_type, string $key ): bool {
		$registered = get_registered_meta_keys( 'post', $post_type );

		return ! empty( $registered[ $key ]['show_in_rest'] );
	}

	/**
	 * Register the active SEO plugin's string meta for REST reads and writes,
	 * skipping anything the SEO plugin already exposes itself.
	 */
	public static function register(): void {
		$active = self::detect();

		// No supported SEO plugin: register nothing rather than create orphan meta.
		if ( empty( $active ) ) {
			return;
		}

		$post_types = apply_filters( 'ppt_seo_meta_post_types', self::POST_TYPES );
		if ( ! is_array( $post_types ) || empty( $post_types ) ) {
			return;
		}

		/**
		 * Gate writes on the capability for the specific post.
		 *
		 * WordPress passes ( $allowed, $meta_key, $object_id, $user_id, $cap,
		 * $caps ); the post id is all we need. Never return true here — that
		 * would let any authenticated user, a Subscriber included, rewrite SEO
		 * meta on any post.
		 */
		$auth = static function ( $allowed, $meta_key, $post_id ) {
			return current_user_can( 'edit_post', (int) $post_id );
		};

		foreach ( $active as $slug ) {
			foreach ( self::KEYS[ $slug ] as $key => $description ) {
				foreach ( $post_types as $post_type ) {
					$post_type = (string) $post_type;

					// The SEO plugin already exposes this key; re-registering
					// would replace its sanitize and auth callbacks with ours.
					if ( self::already_exposed( $post_type, $key ) ) {
						continue;
					}

					register_post_meta(
						$post_type,
						$key,
						array(
							'single'            => true,
							'type'              => 'string',
							'description'       => $description,
							'show_in_rest'      => true,
							'sanitize_callback' => 'sanitize_text_field',
							'auth_callback'     => $auth,
						)
					);
				}
			}
		}
	}
}
