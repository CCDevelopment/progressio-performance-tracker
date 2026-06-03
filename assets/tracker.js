/**
 * Progressio Performance Tracker — tracker.js
 *
 * Fires GA4 custom events for:
 *   - CTA button clicks (primary / secondary / tertiary)
 *   - Form submissions (WS Form, Gravity Forms, WPForms, CF7, Fluent, Formidable, Ninja, generic)
 *   - Scroll depth milestones
 *   - Outbound link clicks
 *   - Phone number clicks (tel:)
 *   - Email clicks (mailto:)
 *
 * All conversion events include traffic attribution parameters
 * (source, medium, campaign, keyword) captured from URL params / sessionStorage.
 *
 * Configuration is passed via wp_localize_script as window.pptConfig.
 */

( function () {
	'use strict';

	/* ─── Guard ─────────────────────────────────────────────────────────────── */
	if ( typeof window.pptConfig === 'undefined' ) {
		return;
	}

	var cfg = window.pptConfig;

	/* ─── Helpers ───────────────────────────────────────────────────────────── */

	/**
	 * Send a GA4 event. No-ops silently if gtag is not present.
	 *
	 * @param {string} eventName
	 * @param {Object} params
	 */
	function sendEvent( eventName, params ) {
		if ( typeof window.gtag !== 'function' ) {
			if ( cfg.debugMode ) {
				console.warn( '[PPT] gtag not found. Event not sent:', eventName, params );
			}
			return;
		}

		// Merge in attribution data on every event.
		var fullParams = Object.assign( {}, getAttribution(), params );

		if ( cfg.debugMode ) {
			console.log( '[PPT] Event:', eventName, fullParams );
		}

		window.gtag( 'event', eventName, fullParams );
	}

	/* ─── Attribution ───────────────────────────────────────────────────────── */

	/**
	 * Parse UTM parameters from the URL and persist them in sessionStorage
	 * so attribution survives page navigations within the same session.
	 *
	 * Falls back to document.referrer for organic/direct attribution.
	 */
	function initAttribution() {
		var params  = new URLSearchParams( window.location.search );
		var utmKeys = [ 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid' ];
		var stored  = {};

		// If UTM params exist in the URL, (re-)store them.
		if ( params.get( 'utm_source' ) ) {
			utmKeys.forEach( function ( key ) {
				if ( params.get( key ) ) {
					stored[ key ] = params.get( key );
				}
			} );
			try {
				sessionStorage.setItem( 'ppt_attribution', JSON.stringify( stored ) );
			} catch ( e ) { /* Private browsing — ignore */ }
		}
	}

	// Cached attribution — sessionStorage/referrer don't change within a page load.
	var attributionCache = null;

	/**
	 * Return a flat attribution object ready to merge into any event.
	 * Source/medium/campaign are computed once and cached; page_location and
	 * page_title are refreshed per call to stay accurate for SPA navigations.
	 *
	 * @returns {Object}
	 */
	function getAttribution() {
		if ( ! attributionCache ) {
			attributionCache = {};

			try {
				var raw = sessionStorage.getItem( 'ppt_attribution' );
				if ( raw ) {
					var stored = JSON.parse( raw );
					if ( stored.utm_source )   attributionCache.traffic_source   = stored.utm_source;
					if ( stored.utm_medium )   attributionCache.traffic_medium   = stored.utm_medium;
					if ( stored.utm_campaign ) attributionCache.traffic_campaign = stored.utm_campaign;
					if ( stored.utm_term )     attributionCache.traffic_keyword  = stored.utm_term;
					if ( stored.utm_content )  attributionCache.traffic_content  = stored.utm_content;
					if ( stored.gclid )        attributionCache.gclid            = stored.gclid;
					if ( stored.fbclid )       attributionCache.fbclid           = stored.fbclid;
				}
			} catch ( e ) { /* ignore */ }

			// Referrer fallback when no UTM params exist.
			if ( ! attributionCache.traffic_source && document.referrer ) {
				try {
					var ref    = new URL( document.referrer );
					var domain = ref.hostname.replace( /^www\./, '' );
					if ( domain !== cfg.siteDomain ) {
						attributionCache.traffic_source = domain;
						attributionCache.traffic_medium = 'referral';
					}
				} catch ( e ) { /* invalid referrer URL */ }
			}
		}

		// Clone the cached base and add per-event page context.
		var attribution = Object.assign( {}, attributionCache );
		attribution.page_location = window.location.href;
		attribution.page_title    = document.title;

		return attribution;
	}

	/* ─── Unified Click Tracking ────────────────────────────────────────────── */

	/**
	 * A single delegated click listener handles CTA, outbound, phone, and email
	 * tracking. One listener on the document is cheaper than four.
	 */
	function initClickTracking() {
		// Build the CTA selector map once: cssClass → { label, tier }
		var classMap     = {};
		var hasCtaClasses = false;
		if ( cfg.ctaClasses && cfg.ctaClasses.length ) {
			cfg.ctaClasses.forEach( function ( cta ) {
				if ( cta.cssClass ) {
					classMap[ cta.cssClass ] = { label: cta.label, tier: cta.tier };
					hasCtaClasses = true;
				}
			} );
		}

		var trackCta      = hasCtaClasses;
		var trackOutbound = !! cfg.trackOutbound;
		var trackPhone    = !! cfg.trackPhone;
		var trackEmail    = !! cfg.trackEmail;

		// Nothing to do — skip registering the listener entirely.
		if ( ! trackCta && ! trackOutbound && ! trackPhone && ! trackEmail ) {
			return;
		}

		document.addEventListener( 'click', function ( e ) {
			// ── CTA: walk up to 3 levels to catch clicks on child elements. ──
			if ( trackCta ) {
				var node = e.target;
				for ( var i = 0; i < 3; i++ ) {
					if ( ! node || node === document ) break;
					var classList = ( node.className || '' ).toString().split( /\s+/ );
					for ( var c = 0; c < classList.length; c++ ) {
						if ( classMap[ classList[ c ] ] ) {
							sendEvent( 'cta_click', {
								cta_tier     : classMap[ classList[ c ] ].tier,
								cta_label    : classMap[ classList[ c ] ].label,
								button_text  : ( node.innerText || node.value || '' ).trim().substring( 0, 100 ),
								button_class : ( node.className || '' ).toString().trim(),
								link_url     : node.href || '',
							} );
							return; // Only fire once per click.
						}
					}
					node = node.parentElement;
				}
			}

			// ── Anchor-based handlers share one closest() lookup. ──
			var link = e.target.closest( 'a' );
			if ( ! link ) return;

			// Phone (tel:)
			if ( trackPhone && link.getAttribute( 'href' ) && link.getAttribute( 'href' ).indexOf( 'tel:' ) === 0 ) {
				sendEvent( 'phone_click', {
					phone_number: link.href.replace( 'tel:', '' ),
					link_text   : ( link.innerText || '' ).trim(),
				} );
				return;
			}

			// Email (mailto:)
			if ( trackEmail && link.getAttribute( 'href' ) && link.getAttribute( 'href' ).indexOf( 'mailto:' ) === 0 ) {
				// Do not send the email address — it is PII prohibited by Google's measurement terms.
				sendEvent( 'email_click', {
					link_text: ( link.innerText || '' ).trim(),
				} );
				return;
			}

			// Outbound
			if ( trackOutbound && link.href ) {
				try {
					var url = new URL( link.href );
					if ( url.hostname && url.hostname !== cfg.siteDomain && url.hostname !== ( 'www.' + cfg.siteDomain ) ) {
						sendEvent( 'outbound_click', {
							link_url  : link.href,
							link_text : ( link.innerText || '' ).trim().substring( 0, 100 ),
						} );
					}
				} catch ( err ) { /* invalid URL */ }
			}
		} );
	}

	/* ─── Form Tracking ─────────────────────────────────────────────────────── */

	/**
	 * Normalised form event payload builder.
	 *
	 * @param {string} formId
	 * @param {string} formTitle
	 * @param {string} plugin
	 * @returns {Object}
	 */
	function buildFormPayload( formId, formTitle, plugin ) {
		return {
			form_id     : formId    || 'unknown',
			form_title  : formTitle || 'unknown',
			form_plugin : plugin,
		};
	}

	function initFormTracking() {
		if ( ! cfg.trackForms ) return;

		var plugin = cfg.formPlugin;

		// ── Auto-detect: try all hooks, first success wins.
		var isAuto = plugin === 'auto';

		// In auto mode every handler (incl. generic) is bound. Record when a
		// plugin-specific handler fires so the generic submit fallback can skip
		// the same submission and avoid double-counting.
		var lastSpecificFire = 0;
		function fireSpecific( payload ) {
			lastSpecificFire = Date.now();
			sendEvent( 'form_submit', payload );
		}

		/* WS Form ─────────────────────────────────────────────────────────────
		   Fires a custom JS event 'wsf-submit' on the form element. */
		if ( isAuto || plugin === 'wsform' ) {
			document.addEventListener( 'wsf-submit', function ( e ) {
				var form  = e.target;
				var id    = form ? form.getAttribute( 'data-id' ) : '';
				var title = form ? form.getAttribute( 'data-label' ) : '';
				fireSpecific( buildFormPayload( id, title, 'ws_form' ) );
			} );
		}

		/* Gravity Forms ───────────────────────────────────────────────────────
		   Uses the gform_confirmation_loaded JS event. */
		if ( isAuto || plugin === 'gravityforms' ) {
			// Guard against double-fire when both native and jQuery events dispatch.
			var gfFired = {};
			function fireGravityForm( formId ) {
				if ( gfFired[ formId ] ) return;
				gfFired[ formId ] = true;
				// Reset after a short delay so re-submissions on the same page are tracked.
				setTimeout( function () { delete gfFired[ formId ]; }, 2000 );
				fireSpecific( buildFormPayload( formId, 'Gravity Form ' + formId, 'gravity_forms' ) );
			}

			document.addEventListener( 'gform_confirmation_loaded', function ( e ) {
				fireGravityForm( e.detail ? e.detail.formId : '' );
			} );

			// Secondary hook via jQuery if present (older GF versions).
			if ( typeof jQuery !== 'undefined' ) {
				jQuery( document ).on( 'gform_confirmation_loaded', function ( e, formId ) {
					fireGravityForm( formId );
				} );
			}
		}

		/* WPForms ─────────────────────────────────────────────────────────────
		   Fires wpformsAjaxSubmitSuccess on the form element. */
		if ( isAuto || plugin === 'wpforms' ) {
			document.addEventListener( 'wpformsAjaxSubmitSuccess', function ( e ) {
				var form  = e.target;
				var id    = form ? form.getAttribute( 'data-formid' ) : '';
				fireSpecific( buildFormPayload( id, 'WPForms ' + id, 'wpforms' ) );
			} );
		}

		/* Contact Form 7 ──────────────────────────────────────────────────────
		   Fires wpcf7mailsent on document. */
		if ( isAuto || plugin === 'cf7' ) {
			document.addEventListener( 'wpcf7mailsent', function ( e ) {
				var detail = e.detail || {};
				var id     = detail.contactFormId || detail.id || '';
				fireSpecific( buildFormPayload( id, 'CF7 Form ' + id, 'cf7' ) );
			} );
		}

		/* Fluent Forms ────────────────────────────────────────────────────────
		   Fires fluentform_submission_success. */
		if ( isAuto || plugin === 'fluentforms' ) {
			document.addEventListener( 'fluentform_submission_success', function ( e ) {
				var detail = e.detail || {};
				var id     = detail.response && detail.response.data ? detail.response.data.insert_id : '';
				fireSpecific( buildFormPayload( id, 'Fluent Form', 'fluent_forms' ) );
			} );
		}

		/* Formidable Forms ────────────────────────────────────────────────────
		   Fires frmFormComplete on document. */
		if ( isAuto || plugin === 'formidable' ) {
			document.addEventListener( 'frmFormComplete', function ( e ) {
				var detail = e.detail || {};
				var id     = detail.formId || '';
				fireSpecific( buildFormPayload( id, 'Formidable Form ' + id, 'formidable' ) );
			} );
		}

		/* Ninja Forms ─────────────────────────────────────────────────────────
		   Uses the Backbone model event system. Requires nfRadio. */
		if ( ( isAuto || plugin === 'ninja' ) && typeof window.nfRadio !== 'undefined' ) {
			window.nfRadio.channel( 'forms' ).on( 'submit:response', function ( response ) {
				var id = response && response.data ? response.data.form_id : '';
				fireSpecific( buildFormPayload( id, 'Ninja Form ' + id, 'ninja_forms' ) );
			} );
		}

		/* Generic HTML fallback ───────────────────────────────────────────────
		   Catches any <form> submit that wasn't caught by plugin hooks above.
		   Useful for custom HTML forms or unsupported plugins. */
		if ( isAuto || plugin === 'generic' ) {
			document.addEventListener( 'submit', function ( e ) {
				// In auto mode, skip if a plugin-specific handler just fired for
				// this submission to avoid double-counting.
				if ( isAuto && ( Date.now() - lastSpecificFire ) < 1500 ) {
					return;
				}
				var form   = e.target;
				var id     = form.id || form.getAttribute( 'name' ) || 'unknown';
				var title  = form.getAttribute( 'aria-label' ) || id;
				sendEvent( 'form_submit', buildFormPayload( id, title, 'generic' ) );
			} );
		}
	}

	/* ─── Scroll Depth ──────────────────────────────────────────────────────── */

	function initScrollTracking() {
		if ( ! cfg.trackScroll ) return;

		var milestones   = [ 25, 50, 75, 100 ];
		var fired        = {};
		var firedCount   = 0;
		var ticking      = false;

		function onScroll() {
			if ( ticking ) return;
			ticking = true;
			requestAnimationFrame( function () {
				var scrollTop = window.scrollY || document.documentElement.scrollTop;
				var docHeight = document.documentElement.scrollHeight - window.innerHeight;
				var pct       = docHeight > 0 ? Math.round( ( scrollTop / docHeight ) * 100 ) : 0;

				milestones.forEach( function ( m ) {
					if ( pct >= m && ! fired[ m ] ) {
						fired[ m ] = true;
						firedCount++;
						sendEvent( 'scroll_depth', { percent_scrolled: m } );
					}
				} );

				// All milestones reached — stop listening.
				if ( firedCount >= milestones.length ) {
					window.removeEventListener( 'scroll', onScroll );
				}
				ticking = false;
			} );
		}

		window.addEventListener( 'scroll', onScroll, { passive: true } );
	}

	/* ─── Init ──────────────────────────────────────────────────────────────── */

	// Capture attribution immediately — before any user interaction.
	initAttribution();

	// Wire up all trackers once the DOM is ready.
	function initAll() {
		initClickTracking();
		initFormTracking();
		initScrollTracking();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initAll );
	} else {
		initAll();
	}

} )();
