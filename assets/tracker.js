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
 * Lead capture (name/email/phone → Progressio Leads API) is handled server-side
 * via PHP hooks in class-ppt-tracker.php, not here. This file only handles GA4.
 *
 * Attribution data (UTM params, referrer) is written to a first-party cookie
 * (ppt_attr) so the PHP hooks can include it in lead payloads.
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

	function sendEvent( eventName, params ) {
		if ( typeof window.gtag !== 'function' ) {
			if ( cfg.debugMode ) {
				console.warn( '[PPT] gtag not found. Event not sent:', eventName, params );
			}
			return;
		}

		var fullParams = Object.assign( {}, getAttribution(), params );

		if ( cfg.debugMode ) {
			console.log( '[PPT] Event:', eventName, fullParams );
		}

		window.gtag( 'event', eventName, fullParams );
	}

	/* ─── Attribution ───────────────────────────────────────────────────────── */

	function initAttribution() {
		var params  = new URLSearchParams( window.location.search );
		var utmKeys = [ 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid' ];
		var stored  = {};

		if ( params.get( 'utm_source' ) ) {
			utmKeys.forEach( function ( key ) {
				if ( params.get( key ) ) {
					stored[ key ] = params.get( key );
				}
			} );
			try {
				sessionStorage.setItem( 'ppt_attribution', JSON.stringify( stored ) );
			} catch ( e ) { /* Private browsing */ }
		}
	}

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

	/**
	 * Write a first-party cookie containing UTM/referrer attribution data so
	 * the PHP lead-capture hooks can include it in API payloads. The cookie is
	 * sent automatically with same-site requests (REST API, admin-ajax, etc.).
	 */
	function initAttributionCookie() {
		var data = {};

		try {
			var raw = sessionStorage.getItem( 'ppt_attribution' );
			if ( raw ) {
				data = JSON.parse( raw );
			}
		} catch ( e ) { /* ignore */ }

		// Referrer fallback when no UTM params were stored.
		if ( ! data.utm_source && document.referrer ) {
			try {
				var ref    = new URL( document.referrer );
				var domain = ref.hostname.replace( /^www\./, '' );
				if ( domain !== cfg.siteDomain ) {
					data._referrer_source = domain;
					data._referrer_medium = 'referral';
				}
			} catch ( e ) { /* invalid URL */ }
		}

		// Current page URL — used as pageUrl / landingPage in the lead payload.
		data._page = window.location.href;

		// SameSite=Lax: cookie is sent with same-site requests (incl. REST API calls).
		// No explicit expiry → session cookie, cleared when browser closes.
		document.cookie = 'ppt_attr=' + encodeURIComponent( JSON.stringify( data ) )
			+ '; path=/; SameSite=Lax';
	}

	/* ─── CTA Button Tracking ───────────────────────────────────────────────── */

	function initCTATracking() {
		if ( ! cfg.ctaClasses || ! cfg.ctaClasses.length ) {
			return;
		}

		var classMap = {};
		cfg.ctaClasses.forEach( function ( cta ) {
			if ( cta.cssClass ) {
				classMap[ cta.cssClass ] = { label: cta.label, tier: cta.tier };
			}
		} );

		document.addEventListener( 'click', function ( e ) {
			var target = e.target;

			for ( var i = 0; i < 3; i++ ) {
				if ( ! target || target === document ) break;
				var classList = ( target.className || '' ).toString().split( /\s+/ );

				for ( var c = 0; c < classList.length; c++ ) {
					var cls = classList[ c ];
					if ( classMap[ cls ] ) {
						sendEvent( 'cta_click', {
							cta_tier     : classMap[ cls ].tier,
							cta_label    : classMap[ cls ].label,
							button_text  : ( target.innerText || target.value || '' ).trim().substring( 0, 100 ),
							button_class : ( target.className || '' ).toString().trim(),
							link_url     : target.href || '',
						} );
						return;
					}
				}
				target = target.parentElement;
			}
		} );
	}

	/* ─── Form Tracking (GA4 events only) ──────────────────────────────────── */

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
		var isAuto = plugin === 'auto';

		/* WS Form ─────────────────────────────────────────────────────────────
		   Submits via REST API. We intercept fetch() only to fire the GA4
		   form_submit event — lead capture is handled server-side in PHP. */
		if ( isAuto || plugin === 'wsform' ) {
			var _origFetch = window.fetch;
			window.fetch = function ( resource, init ) {
				var url = typeof resource === 'string' ? resource
					: ( resource && resource.url ) ? resource.url : '';

				if ( url.indexOf( 'ws-form/v1/submit' ) !== -1 ) {
					var wsfBodyId = '';
					if ( init && init.body ) {
						if ( init.body instanceof FormData ) {
							wsfBodyId = String( init.body.get( 'id' ) || '' );
						} else if ( typeof init.body === 'string' ) {
							try { wsfBodyId = String( JSON.parse( init.body ).id || '' ); } catch ( _e ) {}
						}
					}

					var wsfFormEl = wsfBodyId
						? document.querySelector( 'form[data-id="' + wsfBodyId + '"]' )
						: document.querySelector( 'form[id^="wsf-"]' );

					var wsfId    = ( wsfFormEl && wsfFormEl.getAttribute( 'data-id' ) ) || wsfBodyId || '';
					var wsfTitle = ( wsfFormEl && wsfFormEl.getAttribute( 'data-label' ) ) || 'WS Form';

					var wsfPromise = _origFetch.apply( this, arguments );
					wsfPromise.then( function ( response ) {
						if ( ! response.ok ) return;
						response.clone().json().then( function ( data ) {
							if ( ! data.error ) {
								sendEvent( 'form_submit', buildFormPayload( wsfId, wsfTitle, 'ws_form' ) );
							}
						} ).catch( function () {} );
					} ).catch( function () {} );

					return wsfPromise;
				}

				return _origFetch.apply( this, arguments );
			};
		}

		/* Gravity Forms ───────────────────────────────────────────────────────
		   gform_confirmation_loaded fires after the confirmation is shown. */
		if ( isAuto || plugin === 'gravityforms' ) {
			var gfFired = {};
			function fireGravityForm( formId ) {
				if ( gfFired[ formId ] ) return;
				gfFired[ formId ] = true;
				setTimeout( function () { delete gfFired[ formId ]; }, 2000 );
				sendEvent( 'form_submit', buildFormPayload( formId, 'Gravity Form ' + formId, 'gravity_forms' ) );
			}

			document.addEventListener( 'gform_confirmation_loaded', function ( e ) {
				fireGravityForm( e.detail ? e.detail.formId : '' );
			} );

			if ( typeof jQuery !== 'undefined' ) {
				jQuery( document ).on( 'gform_confirmation_loaded', function ( e, formId ) {
					fireGravityForm( formId );
				} );
			}
		}

		/* WPForms ─────────────────────────────────────────────────────────────*/
		if ( isAuto || plugin === 'wpforms' ) {
			document.addEventListener( 'wpformsAjaxSubmitSuccess', function ( e ) {
				var form  = e.target;
				var id    = form ? form.getAttribute( 'data-formid' ) : '';
				sendEvent( 'form_submit', buildFormPayload( id, 'WPForms ' + id, 'wpforms' ) );
			} );
		}

		/* Contact Form 7 ──────────────────────────────────────────────────────*/
		if ( isAuto || plugin === 'cf7' ) {
			document.addEventListener( 'wpcf7mailsent', function ( e ) {
				var detail = e.detail || {};
				var id     = detail.contactFormId || detail.id || '';
				sendEvent( 'form_submit', buildFormPayload( id, 'CF7 Form ' + id, 'cf7' ) );
			} );
		}

		/* Fluent Forms ────────────────────────────────────────────────────────*/
		if ( isAuto || plugin === 'fluentforms' ) {
			document.addEventListener( 'fluentform_submission_success', function ( e ) {
				var detail = e.detail || {};
				var id     = detail.response && detail.response.data ? detail.response.data.insert_id : '';
				sendEvent( 'form_submit', buildFormPayload( id, 'Fluent Form', 'fluent_forms' ) );
			} );
		}

		/* Formidable Forms ────────────────────────────────────────────────────*/
		if ( isAuto || plugin === 'formidable' ) {
			document.addEventListener( 'frmFormComplete', function ( e ) {
				var detail = e.detail || {};
				var id     = detail.formId || '';
				sendEvent( 'form_submit', buildFormPayload( id, 'Formidable Form ' + id, 'formidable' ) );
			} );
		}

		/* Ninja Forms ─────────────────────────────────────────────────────────*/
		if ( ( isAuto || plugin === 'ninja' ) && typeof window.nfRadio !== 'undefined' ) {
			window.nfRadio.channel( 'forms' ).on( 'submit:response', function ( response ) {
				var id = response && response.data ? response.data.form_id : '';
				sendEvent( 'form_submit', buildFormPayload( id, 'Ninja Form ' + id, 'ninja_forms' ) );
			} );
		}

		/* Generic HTML fallback ───────────────────────────────────────────────*/
		if ( isAuto || plugin === 'generic' ) {
			document.addEventListener( 'submit', function ( e ) {
				var form  = e.target;
				var id    = form.id || form.getAttribute( 'name' ) || 'unknown';
				var title = form.getAttribute( 'aria-label' ) || id;
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
				phone_number : target.href.replace( 'tel:', '' ),
				link_text    : ( target.innerText || '' ).trim(),
			} );
		} );
	}

	/* ─── Email Clicks ──────────────────────────────────────────────────────── */

	function initEmailTracking() {
		if ( ! cfg.trackEmail ) return;

		document.addEventListener( 'click', function ( e ) {
			var target = e.target.closest( 'a[href^="mailto:"]' );
			if ( ! target ) return;

			// Do not send the address — PII prohibited by Google's measurement terms.
			sendEvent( 'email_click', {
				link_text: ( target.innerText || '' ).trim(),
			} );
		} );
	}

	/* ─── Init ──────────────────────────────────────────────────────────────── */

	// Capture and persist attribution immediately.
	initAttribution();
	initAttributionCookie();

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
