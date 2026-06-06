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
 * Also posts visitor contact data (name, email, phone) from form submissions
 * to the Progressio Leads endpoint when configured.
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

	/* ─── Lead Capture ──────────────────────────────────────────────────────── */

	/**
	 * Extract visitor contact info (name, email, phone) from a form element.
	 *
	 * Scans visible input/select/textarea fields by type and name heuristics.
	 * Returns an object with any subset of { name, email, phone }.
	 *
	 * @param {HTMLFormElement|null} formEl
	 * @returns {{ name?: string, email?: string, phone?: string }}
	 */
	function extractContactFromForm( formEl ) {
		if ( ! formEl ) return {};

		var contact   = {};
		var firstName = '';
		var lastName  = '';
		var fields    = formEl.querySelectorAll( 'input:not([type="submit"]):not([type="button"]):not([type="hidden"]):not([type="checkbox"]):not([type="radio"]), textarea' );

		fields.forEach( function ( field ) {
			var n   = ( field.name || field.id || field.getAttribute( 'data-name' ) || '' ).toLowerCase();
			var val = ( field.value || '' ).trim();
			if ( ! val ) return;

			// Email
			if ( ! contact.email && ( field.type === 'email' || n.indexOf( 'email' ) !== -1 ) ) {
				contact.email = val;
				return;
			}

			// Phone
			if ( ! contact.phone && ( field.type === 'tel' || n.indexOf( 'phone' ) !== -1 || n.indexOf( 'mobile' ) !== -1 || n.indexOf( 'tel' ) !== -1 ) ) {
				contact.phone = val;
				return;
			}

			// First name
			if ( ! firstName && ( ( n.indexOf( 'first' ) !== -1 && n.indexOf( 'name' ) !== -1 ) || n === 'fname' || n === 'firstname' ) ) {
				firstName = val;
				return;
			}

			// Last name
			if ( ! lastName && ( ( n.indexOf( 'last' ) !== -1 && n.indexOf( 'name' ) !== -1 ) || n === 'lname' || n === 'lastname' || n === 'surname' ) ) {
				lastName = val;
				return;
			}

			// Full name (catch-all: only if no first/last pattern matched)
			if ( ! contact.name && ( n === 'name' || n === 'fullname' || n === 'full_name' || n === 'your-name' || n === 'yourname' || ( n.indexOf( 'name' ) !== -1 && n.indexOf( 'first' ) === -1 && n.indexOf( 'last' ) === -1 ) ) ) {
				contact.name = val;
			}
		} );

		// Combine first + last when available
		if ( ! contact.name && ( firstName || lastName ) ) {
			contact.name = [ firstName, lastName ].filter( Boolean ).join( ' ' );
		}

		return contact;
	}

	/**
	 * Extract contact info from CF7-style inputs array ({ name, value }[]).
	 *
	 * @param {Array} inputs
	 * @returns {{ name?: string, email?: string, phone?: string }}
	 */
	function extractContactFromInputs( inputs ) {
		if ( ! inputs || ! inputs.length ) return {};

		var contact   = {};
		var firstName = '';
		var lastName  = '';

		inputs.forEach( function ( inp ) {
			var n   = ( inp.name || '' ).toLowerCase();
			var val = ( inp.value || '' ).trim();
			if ( ! val ) return;

			if ( ! contact.email && ( n.indexOf( 'email' ) !== -1 ) ) {
				contact.email = val; return;
			}
			if ( ! contact.phone && ( n.indexOf( 'phone' ) !== -1 || n.indexOf( 'mobile' ) !== -1 || n.indexOf( 'tel' ) !== -1 ) ) {
				contact.phone = val; return;
			}
			if ( ! firstName && n.indexOf( 'first' ) !== -1 && n.indexOf( 'name' ) !== -1 ) {
				firstName = val; return;
			}
			if ( ! lastName && n.indexOf( 'last' ) !== -1 && n.indexOf( 'name' ) !== -1 ) {
				lastName = val; return;
			}
			if ( ! contact.name && n.indexOf( 'name' ) !== -1 ) {
				contact.name = val;
			}
		} );

		if ( ! contact.name && ( firstName || lastName ) ) {
			contact.name = [ firstName, lastName ].filter( Boolean ).join( ' ' );
		}
		return contact;
	}

	/**
	 * POST visitor contact data to the Progressio Leads endpoint.
	 * No-ops silently if leadsApiKey or leadsEndpoint are not configured,
	 * or if no contact fields were captured.
	 *
	 * @param {{ name?: string, email?: string, phone?: string }} contact
	 * @param {{ formId?: string, formTitle?: string }} formData
	 */
	function sendLead( contact, formData ) {
		if ( ! cfg.leadsApiKey || ! cfg.leadsEndpoint ) return;
		if ( ! contact.name && ! contact.email && ! contact.phone ) return;

		var attr    = getAttribution();
		var payload = {
			apiKey      : cfg.leadsApiKey,
			name        : contact.name   || null,
			email       : contact.email  || null,
			phone       : contact.phone  || null,
			source      : attr.traffic_source   || null,
			medium      : attr.traffic_medium   || null,
			campaign    : attr.traffic_campaign || null,
			keyword     : attr.traffic_keyword  || null,
			landingPage : attr.page_location    || null,
			formTitle   : formData.formTitle    || null,
			formId      : formData.formId       || null,
			pageUrl     : window.location.href,
		};

		if ( cfg.debugMode ) {
			console.log( '[PPT] Lead:', payload );
		}

		try {
			fetch( cfg.leadsEndpoint, {
				method    : 'POST',
				headers   : { 'Content-Type': 'application/json' },
				body      : JSON.stringify( payload ),
				keepalive : true,
			} ).catch( function () { /* fail silently */ } );
		} catch ( e ) { /* fetch not available — ignore */ }
	}

	/* ─── Pre-submit capture ────────────────────────────────────────────────── */

	// Map of form key → contact data, populated before submission so we have
	// field values even when plugins replace the form with a confirmation message.
	var preSubmitCapture = {};

	function initPreSubmitCapture() {
		if ( ! cfg.leadsApiKey || ! cfg.leadsEndpoint ) return;

		// Capture phase: runs before any plugin's submit handler.
		document.addEventListener( 'submit', function ( e ) {
			var form = e.target;
			if ( ! form ) return;
			var key = form.id || form.getAttribute( 'name' ) || '_form';
			preSubmitCapture[ key ] = extractContactFromForm( form );
			// Discard after 30 s to avoid memory leaks.
			setTimeout( function () { delete preSubmitCapture[ key ]; }, 30000 );
		}, true );
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
		   WS Form fires 'wsf-submit' as a jQuery event on the form element.
		   Native addEventListener won't catch jQuery events, so we use both.
		   Contact data is read from preSubmitCapture because the form is
		   cleared before the post-submit event fires. */
		if ( isAuto || plugin === 'wsform' ) {
			function handleWsfSubmit( form ) {
				var id    = form ? form.getAttribute( 'data-id' ) : '';
				var title = form ? form.getAttribute( 'data-label' ) : '';
				var key   = form ? ( form.id || form.getAttribute( 'name' ) || '_form' ) : '_form';
				var contact = preSubmitCapture[ key ] || extractContactFromForm( form );
				sendEvent( 'form_submit', buildFormPayload( id, title, 'ws_form' ) );
				sendLead( contact, { formId: id, formTitle: title || 'WS Form' } );
				delete preSubmitCapture[ key ];
			}

			// jQuery listener — catches jQuery-triggered wsf-submit events.
			if ( typeof jQuery !== 'undefined' ) {
				jQuery( document ).on( 'wsf-submit', function ( e ) {
					handleWsfSubmit( e.target );
				} );
			}

			// Native listener — catches CustomEvent-dispatched wsf-submit events.
			document.addEventListener( 'wsf-submit', function ( e ) {
				handleWsfSubmit( e.target );
			} );
		}

		/* Gravity Forms ───────────────────────────────────────────────────────
		   Uses the gform_confirmation_loaded JS event. Form DOM is replaced
		   by confirmation by this point — use preSubmitCapture. */
		if ( isAuto || plugin === 'gravityforms' ) {
			// Guard against double-fire when both native and jQuery events dispatch.
			var gfFired = {};
			function fireGravityForm( formId ) {
				if ( gfFired[ formId ] ) return;
				gfFired[ formId ] = true;
				// Reset after a short delay so re-submissions on the same page are tracked.
				setTimeout( function () { delete gfFired[ formId ]; }, 2000 );
				var formTitle = 'Gravity Form ' + formId;
				sendEvent( 'form_submit', buildFormPayload( formId, formTitle, 'gravity_forms' ) );
				var key     = 'gform_' + formId;
				var contact = preSubmitCapture[ key ] || {};
				sendLead( contact, { formId: formId, formTitle: formTitle } );
				delete preSubmitCapture[ key ];
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
				var title = 'WPForms ' + id;
				sendEvent( 'form_submit', buildFormPayload( id, title, 'wpforms' ) );
				sendLead( extractContactFromForm( form ), { formId: id, formTitle: title } );
			} );
		}

		/* Contact Form 7 ──────────────────────────────────────────────────────
		   Fires wpcf7mailsent on document. e.detail.inputs has field values. */
		if ( isAuto || plugin === 'cf7' ) {
			document.addEventListener( 'wpcf7mailsent', function ( e ) {
				var detail = e.detail || {};
				var id     = detail.contactFormId || detail.id || '';
				var title  = 'CF7 Form ' + id;
				sendEvent( 'form_submit', buildFormPayload( id, title, 'cf7' ) );
				sendLead( extractContactFromInputs( detail.inputs ), { formId: id, formTitle: title } );
			} );
		}

		/* Fluent Forms ────────────────────────────────────────────────────────
		   Fires fluentform_submission_success. Use preSubmitCapture fallback. */
		if ( isAuto || plugin === 'fluentforms' ) {
			document.addEventListener( 'fluentform_submission_success', function ( e ) {
				var detail = e.detail || {};
				var id     = detail.response && detail.response.data ? detail.response.data.insert_id : '';
				var title  = 'Fluent Form';
				sendEvent( 'form_submit', buildFormPayload( id, title, 'fluent_forms' ) );
				// Try to find contact from the form element if still in DOM, else preSubmitCapture
				var formEl  = e.target instanceof HTMLFormElement ? e.target : null;
				var contact = formEl ? extractContactFromForm( formEl ) : ( preSubmitCapture[ '_form' ] || {} );
				sendLead( contact, { formId: id, formTitle: title } );
			} );
		}

		/* Formidable Forms ────────────────────────────────────────────────────
		   Fires frmFormComplete on document. Use preSubmitCapture. */
		if ( isAuto || plugin === 'formidable' ) {
			document.addEventListener( 'frmFormComplete', function ( e ) {
				var detail = e.detail || {};
				var id     = detail.formId || '';
				var title  = 'Formidable Form ' + id;
				sendEvent( 'form_submit', buildFormPayload( id, title, 'formidable' ) );
				var contact = preSubmitCapture[ '_form' ] || {};
				sendLead( contact, { formId: id, formTitle: title } );
			} );
		}

		/* Ninja Forms ─────────────────────────────────────────────────────────
		   Uses the Backbone model event system. Requires nfRadio. */
		if ( ( isAuto || plugin === 'ninja' ) && typeof window.nfRadio !== 'undefined' ) {
			window.nfRadio.channel( 'forms' ).on( 'submit:response', function ( response ) {
				var id    = response && response.data ? response.data.form_id : '';
				var title = 'Ninja Form ' + id;
				sendEvent( 'form_submit', buildFormPayload( id, title, 'ninja_forms' ) );
				var contact = preSubmitCapture[ '_form' ] || {};
				sendLead( contact, { formId: id, formTitle: title } );
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
				sendLead( extractContactFromForm( form ), { formId: id, formTitle: title } );
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
			initPreSubmitCapture();
			initCTATracking();
			initFormTracking();
			initScrollTracking();
			initOutboundTracking();
			initPhoneTracking();
			initEmailTracking();
		} );
	} else {
		initPreSubmitCapture();
		initCTATracking();
		initFormTracking();
		initScrollTracking();
		initOutboundTracking();
		initPhoneTracking();
		initEmailTracking();
	}

} )();
