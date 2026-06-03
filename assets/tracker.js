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

	/**
	 * Return a flat attribution object ready to merge into any event.
	 *
	 * @returns {Object}
	 */
	function getAttribution() {
		var attribution = {};

		try {
			var raw = sessionStorage.getItem( 'ppt_attribution' );
			if ( raw ) {
				var stored = JSON.parse( raw );
				if ( stored.utm_source )   attribution.traffic_source   = stored.utm_source;
				if ( stored.utm_medium )   attribution.traffic_medium   = stored.utm_medium;
				if ( stored.utm_campaign ) attribution.traffic_campaign = stored.utm_campaign;
				if ( stored.utm_term )     attribution.traffic_keyword  = stored.utm_term;
				if ( stored.utm_content )  attribution.traffic_content  = stored.utm_content;
				if ( stored.gclid )        attribution.gclid            = stored.gclid;
				if ( stored.fbclid )       attribution.fbclid           = stored.fbclid;
			}
		} catch ( e ) { /* ignore */ }

		// Referrer fallback when no UTM params exist.
		if ( ! attribution.traffic_source && document.referrer ) {
			try {
				var ref    = new URL( document.referrer );
				var domain = ref.hostname.replace( /^www\./, '' );
				if ( domain !== cfg.siteDomain ) {
					attribution.traffic_source = domain;
					attribution.traffic_medium = 'referral';
				}
			} catch ( e ) { /* invalid referrer URL */ }
		}

		attribution.page_location = window.location.href;
		attribution.page_title    = document.title;

		return attribution;
	}

	/* ─── CTA Button Tracking ───────────────────────────────────────────────── */

	function initCTATracking() {
		if ( ! cfg.ctaClasses || ! cfg.ctaClasses.length ) {
			return;
		}

		// Build a selector map: cssClass → { label, tier }
		var classMap = {};
		cfg.ctaClasses.forEach( function ( cta ) {
			if ( cta.cssClass ) {
				classMap[ cta.cssClass ] = { label: cta.label, tier: cta.tier };
			}
		} );

		// Single delegated listener on the document for performance.
		document.addEventListener( 'click', function ( e ) {
			var target = e.target;

			// Walk up to 3 levels to catch clicks on child elements (e.g. <span> inside <button>).
			for ( var i = 0; i < 3; i++ ) {
				if ( ! target || target === document ) break;
				var classList = ( target.className || '' ).toString().split( /\s+/ );

				for ( var c = 0; c < classList.length; c++ ) {
					var cls = classList[ c ];
					if ( classMap[ cls ] ) {
						sendEvent( 'cta_click', {
							cta_tier      : classMap[ cls ].tier,
							cta_label     : classMap[ cls ].label,
							button_text   : ( target.innerText || target.value || '' ).trim().substring( 0, 100 ),
							button_class  : ( target.className || '' ).toString().trim(),
							link_url      : target.href || '',
						} );
						return; // Only fire once per click.
					}
				}
				target = target.parentElement;
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

		/* WS Form ─────────────────────────────────────────────────────────────
		   Fires a custom JS event 'wsf-submit' on the form element. */
		if ( isAuto || plugin === 'wsform' ) {
			document.addEventListener( 'wsf-submit', function ( e ) {
				var form  = e.target;
				var id    = form ? form.getAttribute( 'data-id' ) : '';
				var title = form ? form.getAttribute( 'data-label' ) : '';
				sendEvent( 'form_submit', buildFormPayload( id, title, 'ws_form' ) );
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
				sendEvent( 'form_submit', buildFormPayload( formId, 'Gravity Form ' + formId, 'gravity_forms' ) );
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
				sendEvent( 'form_submit', buildFormPayload( id, 'WPForms ' + id, 'wpforms' ) );
			} );
		}

		/* Contact Form 7 ──────────────────────────────────────────────────────
		   Fires wpcf7mailsent on document. */
		if ( isAuto || plugin === 'cf7' ) {
			document.addEventListener( 'wpcf7mailsent', function ( e ) {
				var detail = e.detail || {};
				var id     = detail.contactFormId || detail.id || '';
				sendEvent( 'form_submit', buildFormPayload( id, 'CF7 Form ' + id, 'cf7' ) );
			} );
		}

		/* Fluent Forms ────────────────────────────────────────────────────────
		   Fires fluentform_submission_success. */
		if ( isAuto || plugin === 'fluentforms' ) {
			document.addEventListener( 'fluentform_submission_success', function ( e ) {
				var detail = e.detail || {};
				var id     = detail.response && detail.response.data ? detail.response.data.insert_id : '';
				sendEvent( 'form_submit', buildFormPayload( id, 'Fluent Form', 'fluent_forms' ) );
			} );
		}

		/* Formidable Forms ────────────────────────────────────────────────────
		   Fires frmFormComplete on document. */
		if ( isAuto || plugin === 'formidable' ) {
			document.addEventListener( 'frmFormComplete', function ( e ) {
				var detail = e.detail || {};
				var id     = detail.formId || '';
				sendEvent( 'form_submit', buildFormPayload( id, 'Formidable Form ' + id, 'formidable' ) );
			} );
		}

		/* Ninja Forms ─────────────────────────────────────────────────────────
		   Uses the Backbone model event system. Requires nfRadio. */
		if ( ( isAuto || plugin === 'ninja' ) && typeof window.nfRadio !== 'undefined' ) {
			window.nfRadio.channel( 'forms' ).on( 'submit:response', function ( response ) {
				var id = response && response.data ? response.data.form_id : '';
				sendEvent( 'form_submit', buildFormPayload( id, 'Ninja Form ' + id, 'ninja_forms' ) );
			} );
		}

		/* Generic HTML fallback ───────────────────────────────────────────────
		   Catches any <form> submit that wasn't caught by plugin hooks above.
		   Useful for custom HTML forms or unsupported plugins. */
		if ( isAuto || plugin === 'generic' ) {
			document.addEventListener( 'submit', function ( e ) {
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

		var milestones = [ 25, 50, 75, 100 ];
		var fired      = {};
		var ticking    = false;

		window.addEventListener( 'scroll', function () {
			if ( ticking ) return;
			ticking = true;
			requestAnimationFrame( function () {
				var scrollTop = window.scrollY || document.documentElement.scrollTop;
				var docHeight = document.documentElement.scrollHeight - window.innerHeight;
				var pct       = docHeight > 0 ? Math.round( ( scrollTop / docHeight ) * 100 ) : 0;

				milestones.forEach( function ( m ) {
					if ( pct >= m && ! fired[ m ] ) {
						fired[ m ] = true;
						sendEvent( 'scroll_depth', { percent_scrolled: m } );
					}
				} );
				ticking = false;
			} );
		}, { passive: true } );
	}

	/* ─── Outbound Links ────────────────────────────────────────────────────── */

	function initOutboundTracking() {
		if ( ! cfg.trackOutbound ) return;

		document.addEventListener( 'click', function ( e ) {
			var target = e.target.closest( 'a' );
			if ( ! target || ! target.href ) return;

			try {
				var url = new URL( target.href );
				if ( url.hostname && url.hostname !== cfg.siteDomain && url.hostname !== ( 'www.' + cfg.siteDomain ) ) {
					sendEvent( 'outbound_click', {
						link_url  : target.href,
						link_text : ( target.innerText || '' ).trim().substring( 0, 100 ),
					} );
				}
			} catch ( err ) { /* invalid URL */ }
		} );
	}

	/* ─── Phone Clicks ──────────────────────────────────────────────────────── */

	function initPhoneTracking() {
		if ( ! cfg.trackPhone ) return;

		document.addEventListener( 'click', function ( e ) {
			var target = e.target.closest( 'a[href^="tel:"]' );
			if ( ! target ) return;

			sendEvent( 'phone_click', {
				phone_number: target.href.replace( 'tel:', '' ),
				link_text   : ( target.innerText || '' ).trim(),
			} );
		} );
	}

	/* ─── Email Clicks ──────────────────────────────────────────────────────── */

	function initEmailTracking() {
		if ( ! cfg.trackEmail ) return;

		document.addEventListener( 'click', function ( e ) {
			var target = e.target.closest( 'a[href^="mailto:"]' );
			if ( ! target ) return;

			// Do not send the email address — it is PII prohibited by Google's measurement terms.
			sendEvent( 'email_click', {
				link_text: ( target.innerText || '' ).trim(),
			} );
		} );
	}

	/* ─── Init ──────────────────────────────────────────────────────────────── */

	// Capture attribution immediately — before any user interaction.
	initAttribution();

	// Wire up all trackers once the DOM is ready.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			initCTATracking();
			initFormTracking();
			initScrollTracking();
			initOutboundTracking();
			initPhoneTracking();
			initEmailTracking();
		} );
	} else {
		initCTATracking();
		initFormTracking();
		initScrollTracking();
		initOutboundTracking();
		initPhoneTracking();
		initEmailTracking();
	}

} )();
