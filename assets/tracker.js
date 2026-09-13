/**
 * Progressio Performance Tracker — tracker.js
 *
 * Fires GA4 custom events for:
 *   - CTA clicks (data-ppt-cta attributes, or primary / secondary / tertiary classes)
 *   - Form submissions (WS Form, Gravity Forms, WPForms, CF7, Fluent, Formidable,
 *     Ninja, Elementor Pro, generic) and generate_lead once the Leads API accepts one
 *   - Scroll depth milestones
 *   - Outbound link clicks, file downloads, phone (tel:) and email (mailto:) clicks
 *   - Video engagement (YouTube, Vimeo, HTML5)
 *
 * Attribution:
 *   First touch (how the visitor originally arrived) lives in the `ppt_ft`
 *   cookie for cfg.attributionDays; last touch lives in the `ppt_lt` session
 *   cookie. Both are read server-side by class-ppt-leads.php. The cookie
 *   format uses short keys — keep PPT_Attribution::KEYS in sync.
 *
 * Configuration arrives as window.pptConfig (see class-ppt-tracker.php).
 */

( function () {
	'use strict';

	if ( typeof window.pptConfig === 'undefined' ) {
		return;
	}

	var cfg  = window.pptConfig;
	var SITE = String( cfg.siteDomain || '' ).toLowerCase().replace( /^www\./, '' );

	var UTM_KEYS   = { utm_source: 's', utm_medium: 'm', utm_campaign: 'c', utm_term: 't', utm_content: 'n' };
	var CLICK_KEYS = { gclid: 'g', fbclid: 'f', msclkid: 'ms', ttclid: 'tt', li_fat_id: 'li', dclid: 'dc' };
	var KEEP_PARAMS = Object.keys( UTM_KEYS ).concat( Object.keys( CLICK_KEYS ) );
	var MAX_LEN = 200;

	var COOKIE_FIRST = 'ppt_ft';
	var COOKIE_LAST  = 'ppt_lt';
	var COOKIE_LEAD  = 'ppt_last_lead';

	var firstTouch = null;
	var lastTouch  = null;

	/* ─── Helpers ───────────────────────────────────────────────────────────── */

	function debug() {
		if ( cfg.debugMode && window.console ) {
			console.log.apply( console, [ '[PPT]' ].concat( Array.prototype.slice.call( arguments ) ) );
		}
	}

	function sendEvent( eventName, params ) {
		var full = Object.assign( {}, attributionParams(), params || {} );
		debug( 'Event:', eventName, full );

		if ( typeof window.gtag === 'function' ) {
			window.gtag( 'event', eventName, full );
			return;
		}
		// gtag.js may still be loading (Site Kit loads it asynchronously). gtag()
		// itself is just dataLayer.push(arguments), so queue the same way and the
		// library will replay it once it arrives.
		window.dataLayer = window.dataLayer || [];
		window.dataLayer.push( ( function () { return arguments; } )( 'event', eventName, full ) );
	}

	function clip( value, len ) {
		return String( value == null ? '' : value ).trim().substring( 0, len || MAX_LEN );
	}

	function cssEscape( value ) {
		if ( window.CSS && typeof window.CSS.escape === 'function' ) {
			return window.CSS.escape( value );
		}
		return String( value ).replace( /[^a-zA-Z0-9_-]/g, '\\$&' );
	}

	function stripWww( host ) {
		return String( host || '' ).toLowerCase().replace( /^www\./, '' );
	}

	function isInternalHost( host ) {
		return stripWww( host ) === SITE;
	}

	function uuid() {
		if ( window.crypto && typeof window.crypto.randomUUID === 'function' ) {
			return window.crypto.randomUUID();
		}
		var bytes = new Uint8Array( 16 );
		if ( window.crypto && window.crypto.getRandomValues ) {
			window.crypto.getRandomValues( bytes );
		} else {
			for ( var i = 0; i < 16; i++ ) bytes[ i ] = Math.floor( Math.random() * 256 );
		}
		var hex = Array.prototype.map.call( bytes, function ( b ) { return ( '0' + b.toString( 16 ) ).slice( -2 ); } ).join( '' );
		return hex.replace( /^(.{8})(.{4})(.{4})(.{4})(.{12})$/, '$1-$2-$3-$4-$5' );
	}

	/* ─── Cookies ───────────────────────────────────────────────────────────── */

	function getCookie( name ) {
		var match = document.cookie.match( new RegExp( '(?:^|; )' + name + '=([^;]*)' ) );
		if ( ! match ) return '';
		try { return decodeURIComponent( match[ 1 ] ); } catch ( e ) { return ''; }
	}

	function setCookie( name, value, maxAge ) {
		var parts = [ name + '=' + encodeURIComponent( value ), 'path=/', 'SameSite=Lax' ];
		if ( maxAge ) parts.push( 'Max-Age=' + maxAge );
		if ( location.protocol === 'https:' ) parts.push( 'Secure' );
		document.cookie = parts.join( '; ' );
	}

	function deleteCookie( name ) {
		setCookie( name, '', -1 );
	}

	function readJsonCookie( name ) {
		var raw = getCookie( name );
		if ( ! raw ) return null;
		try {
			var data = JSON.parse( raw );
			return data && typeof data === 'object' ? data : null;
		} catch ( e ) {
			return null;
		}
	}

	/* ─── Attribution ───────────────────────────────────────────────────────── */

	/**
	 * Path plus only the marketing query parameters — never the full query
	 * string, which can carry tokens or personal data.
	 */
	function sanitizedPath() {
		var params = new URLSearchParams( location.search );
		var keep   = [];
		KEEP_PARAMS.forEach( function ( key ) {
			var v = params.get( key );
			if ( v ) keep.push( encodeURIComponent( key ) + '=' + encodeURIComponent( clip( v ) ) );
		} );
		return location.pathname + ( keep.length ? '?' + keep.join( '&' ) : '' );
	}

	/**
	 * Mirrors PPT_Attribution::classify() — keep the two in sync.
	 */
	function classifyChannel( t ) {
		var src = String( t.s || '' ).toLowerCase();
		var med = String( t.m || '' ).toLowerCase();
		var ref = String( t.r || '' ).toLowerCase();

		var searchRe    = /(^|\.)(google|bing|yahoo|duckduckgo|baidu|yandex|ecosia|ask|aol|brave|startpage|qwant)\./;
		var socialRe    = /(^|\.)(facebook|instagram|linkedin|twitter|x|t|tiktok|pinterest|youtube|reddit|threads|snapchat|nextdoor|tumblr|quora)\.(com|net|co|be|me)$/;
		var socialSrcRe = /^(facebook|fb|ig|instagram|linkedin|twitter|x|tiktok|pinterest|youtube|reddit|threads|snapchat|nextdoor|meta)$/;

		var isSearch = searchRe.test( src + '.' ) || searchRe.test( ref + '.' );
		var isSocial = socialSrcRe.test( src ) || socialRe.test( src ) || socialRe.test( ref );

		if ( t.dc ) return 'Display';
		if ( t.g || t.ms ) return isSocial ? 'Paid Social' : 'Paid Search';
		if ( t.f || t.tt || t.li ) return 'Paid Social';

		if ( [ 'paid_social', 'paidsocial', 'paid-social', 'social_paid', 'social-paid' ].indexOf( med ) !== -1 ) return 'Paid Social';
		if ( [ 'cpc', 'ppc', 'paidsearch', 'paid_search', 'paid-search', 'paid', 'sem', 'cpv', 'cpa', 'retargeting' ].indexOf( med ) !== -1 ) return isSocial ? 'Paid Social' : 'Paid Search';
		if ( [ 'display', 'banner', 'cpm', 'expandable', 'interstitial' ].indexOf( med ) !== -1 ) return 'Display';
		if ( med === 'organic' ) return 'Organic Search';
		if ( [ 'social', 'social-network', 'social-media', 'social_network', 'social_media', 'sm' ].indexOf( med ) !== -1 ) return 'Organic Social';
		if ( [ 'email', 'e-mail', 'e_mail', 'e mail', 'newsletter' ].indexOf( med ) !== -1 ) return 'Email';
		if ( med === 'affiliate' ) return 'Affiliate';
		if ( med === 'sms' ) return 'SMS';

		if ( isSearch ) return 'Organic Search';
		if ( isSocial ) return 'Organic Social';
		if ( src || ref ) return 'Referral';
		return 'Direct';
	}

	/**
	 * Detect a new inbound touch on this page view: campaign parameters or
	 * click IDs in the URL, else an external referrer. Returns null when the
	 * page view is internal navigation.
	 */
	function detectTouch() {
		var params = new URLSearchParams( location.search );
		var touch  = {};
		var found  = false;

		Object.keys( UTM_KEYS ).forEach( function ( key ) {
			var v = params.get( key );
			if ( v ) { touch[ UTM_KEYS[ key ] ] = clip( v ); found = true; }
		} );
		Object.keys( CLICK_KEYS ).forEach( function ( key ) {
			var v = params.get( key );
			if ( v ) { touch[ CLICK_KEYS[ key ] ] = clip( v ); found = true; }
		} );

		if ( found ) {
			// Auto-tagged ad clicks carry a click ID but no utm_* — infer the network.
			if ( ! touch.s ) {
				touch.s = touch.g ? 'google' : touch.ms ? 'bing' : touch.f ? 'facebook' : touch.tt ? 'tiktok' : touch.li ? 'linkedin' : touch.dc ? 'dv360' : '';
			}
			if ( ! touch.m ) {
				touch.m = ( touch.g || touch.ms ) ? 'cpc' : ( touch.f || touch.tt || touch.li ) ? 'paid_social' : touch.dc ? 'display' : '';
			}
			if ( ! touch.s ) delete touch.s;
			if ( ! touch.m ) delete touch.m;
			return touch;
		}

		if ( document.referrer ) {
			try {
				var ref = new URL( document.referrer );
				if ( ref.hostname && ! isInternalHost( ref.hostname ) ) {
					var host = stripWww( ref.hostname );
					return { s: host, m: 'referral', r: host };
				}
			} catch ( e ) { /* invalid referrer URL */ }
		}

		return null;
	}

	function initAttribution() {
		var now   = Math.floor( Date.now() / 1000 );
		var touch = detectTouch();
		var ttl   = ( parseInt( cfg.attributionDays, 10 ) || 90 ) * 86400;

		firstTouch = readJsonCookie( COOKIE_FIRST );
		lastTouch  = readJsonCookie( COOKIE_LAST );
		var newSession = ! lastTouch;

		if ( touch ) {
			touch.lp = sanitizedPath();
			touch.ts = now;
			touch.ch = classifyChannel( touch );
			lastTouch = touch;
			setCookie( COOKIE_LAST, JSON.stringify( lastTouch ) );
		} else if ( newSession ) {
			lastTouch = { lp: sanitizedPath(), ts: now, ch: 'Direct' };
			setCookie( COOKIE_LAST, JSON.stringify( lastTouch ) );
		}

		if ( ! firstTouch ) {
			firstTouch   = Object.assign( {}, lastTouch );
			firstTouch.v = uuid();
			setCookie( COOKIE_FIRST, JSON.stringify( firstTouch ), ttl );
		} else if ( newSession || ! firstTouch.v ) {
			// Sliding window: extend once per session; backfill a visitor id if missing.
			if ( ! firstTouch.v ) firstTouch.v = uuid();
			setCookie( COOKIE_FIRST, JSON.stringify( firstTouch ), ttl );
		}

		debug( 'Attribution', { first: firstTouch, last: lastTouch } );
	}

	/**
	 * Attribution params attached to every GA4 event. traffic_* describes the
	 * last non-direct touch (session touch when it has one, else first touch);
	 * first_* always describes the first touch.
	 */
	function attributionParams() {
		var ft = firstTouch || {};
		var lt = lastTouch || {};
		var t  = ( lt.s || lt.g || lt.f || lt.ms || lt.tt || lt.li || lt.dc ) ? lt : ( ft.s ? ft : lt );
		var p  = {};

		if ( t.s )  p.traffic_source   = t.s;
		if ( t.m )  p.traffic_medium   = t.m;
		if ( t.c )  p.traffic_campaign = t.c;
		if ( t.t )  p.traffic_keyword  = t.t;
		if ( t.n )  p.traffic_content  = t.n;
		if ( t.g )  p.gclid   = t.g;
		if ( t.f )  p.fbclid  = t.f;
		if ( t.ms ) p.msclkid = t.ms;
		if ( t.tt ) p.ttclid  = t.tt;
		p.traffic_channel = t.ch || 'Direct';

		if ( ft.s )  p.first_source = ft.s;
		if ( ft.m )  p.first_medium = ft.m;
		p.first_channel = ft.ch || 'Direct';
		if ( ft.lp ) p.landing_page = ft.lp;
		if ( ft.v )  p.ppt_visitor_id = ft.v;

		return p;
	}

	/* ─── CTA Button Tracking ───────────────────────────────────────────────── */

	function initCTATracking() {
		var ctas   = [];
		var labels = {};

		( cfg.ctaClasses || [] ).forEach( function ( cta ) {
			if ( cta.label && cta.tier ) labels[ cta.tier ] = cta.label;
			if ( ! cta.cssClass ) return;

			// Space-separated classes are ANDed: "btn btn--action" → ".btn.btn--action".
			var selector = String( cta.cssClass )
				.split( /\s+/ )
				.filter( Boolean )
				.map( function ( cls ) { return '.' + cssEscape( cls.replace( /^\.+/, '' ) ); } )
				.join( '' );
			if ( ! selector ) return;

			try {
				document.querySelector( selector );
			} catch ( err ) {
				debug( 'Ignoring unusable CTA class:', cta.cssClass );
				return;
			}
			ctas.push( { selector: selector, label: cta.label, tier: cta.tier } );
		} );

		var combined = ctas.map( function ( c ) { return c.selector; } ).join( ',' );

		document.addEventListener( 'click', function ( e ) {
			if ( ! e.target || ! e.target.closest ) return;

			// Only count clicks that landed on something actually clickable, so a
			// CTA class on a page-builder wrapper cannot turn every click inside
			// it into an event.
			var control = e.target.closest( 'a, button, input[type="submit"], input[type="button"], [role="button"]' );
			if ( ! control ) return;

			var container, tier, label;

			// data-ppt-cta wins over class matching.
			var attrEl = control.closest( '[data-ppt-cta]' );
			if ( attrEl ) {
				container = attrEl;
				tier      = clip( attrEl.getAttribute( 'data-ppt-cta' ) || 'primary', 50 ).toLowerCase();
				label     = clip( attrEl.getAttribute( 'data-ppt-label' ) || labels[ tier ] || tier, 100 );
			} else if ( combined ) {
				container = control.closest( combined );
				if ( ! container ) return;
				for ( var i = 0; i < ctas.length; i++ ) {
					if ( container.matches( ctas[ i ].selector ) ) {
						tier  = ctas[ i ].tier;
						label = ctas[ i ].label;
						break;
					}
				}
			} else {
				return;
			}

			sendEvent( 'cta_click', {
				cta_tier     : tier,
				cta_label    : label,
				button_text  : clip( control.innerText || control.textContent || control.value, 100 ),
				button_class : clip( ( container.className || '' ).toString(), 100 ),
				link_url     : control.href || '',
			} );
		} );
	}

	/* ─── Form Tracking ─────────────────────────────────────────────────────── */

	var KNOWN_FORM_SELECTOR = [
		'.wpcf7-form', '.wpforms-form', 'form[id^="gform_"]', '.gform_wrapper form',
		'form.wsf-form', 'form[id^="ws-form-"]', 'form[data-wsf-form-id]',
		'.frm-fluent-form', 'form[class*="fluent_form"]', '.frm-show-form',
		'.nf-form-cont form', '.elementor-form',
	].join( ',' );

	var IGNORED_FORM_SELECTOR = 'form[role="search"], .search-form, #searchform, #commentform, #loginform, form.woocommerce-cart-form, form.woocommerce-ordering';

	var lastFormFire = 0;

	function fireFormSubmit( formId, formTitle, plugin ) {
		var now = Date.now();
		// The same submission can surface through more than one listener (native
		// submit + plugin success). One form_submit per 1.5s is plenty.
		if ( now - lastFormFire < 1500 ) return;
		lastFormFire = now;

		var params = {
			form_id     : clip( formId || 'unknown', 100 ),
			form_title  : clip( formTitle || 'unknown', 100 ),
			form_plugin : plugin,
		};
		sendEvent( 'form_submit', params );
		maybeFireGenerateLead( params );
	}

	/**
	 * The PHP side sets a short-lived cookie when the Leads API accepts a lead.
	 * For AJAX submissions it arrives with the success response, so it is
	 * available by the time the plugin's success event fires.
	 */
	function maybeFireGenerateLead( formParams ) {
		if ( ! cfg.leadsEnabled ) return;
		var lead = getCookie( COOKIE_LEAD );
		if ( ! lead ) return;
		deleteCookie( COOKIE_LEAD );

		var params = Object.assign( {}, formParams );
		if ( lead !== '1' ) params.lead_id = clip( lead, 64 );
		sendEvent( 'generate_lead', params );
	}

	function initFormTracking() {
		if ( ! cfg.trackForms ) return;

		var plugin = cfg.formPlugin;
		var isAuto = plugin === 'auto';

		// Non-AJAX submissions (e.g. Gravity Forms redirect confirmations) land
		// on a new page with the lead cookie still set.
		maybeFireGenerateLead( { form_id: 'unknown', form_title: 'unknown', form_plugin: 'server' } );

		/* WS Form — submits via REST; intercept fetch() to catch the success. */
		if ( isAuto || plugin === 'wsform' ) {
			var _origFetch = window.fetch;
			if ( typeof _origFetch === 'function' ) {
				window.fetch = function ( resource, init ) {
					var url = typeof resource === 'string' ? resource : ( resource && resource.url ) ? resource.url : '';
					var promise = _origFetch.apply( this, arguments );

					if ( url.indexOf( 'ws-form/v1/submit' ) !== -1 ) {
						var bodyId = '';
						try {
							if ( init && init.body instanceof FormData ) {
								bodyId = String( init.body.get( 'id' ) || '' );
							} else if ( init && typeof init.body === 'string' ) {
								bodyId = String( JSON.parse( init.body ).id || '' );
							}
						} catch ( _e ) {}

						var formEl = bodyId ? document.querySelector( 'form[data-id="' + cssEscape( bodyId ) + '"]' ) : document.querySelector( 'form[id^="ws-form-"]' );
						var id     = ( formEl && formEl.getAttribute( 'data-id' ) ) || bodyId || '';
						var title  = ( formEl && formEl.getAttribute( 'data-label' ) ) || 'WS Form';

						promise.then( function ( response ) {
							if ( ! response.ok ) return;
							response.clone().json().then( function ( data ) {
								if ( ! data.error ) fireFormSubmit( id, title, 'ws_form' );
							} ).catch( function () {} );
						} ).catch( function () {} );
					}

					return promise;
				};
			}
		}

		/* Gravity Forms */
		if ( isAuto || plugin === 'gravityforms' ) {
			document.addEventListener( 'gform_confirmation_loaded', function ( e ) {
				var id = e.detail ? e.detail.formId : '';
				fireFormSubmit( id, 'Gravity Form ' + id, 'gravity_forms' );
			} );
			if ( typeof jQuery !== 'undefined' ) {
				jQuery( document ).on( 'gform_confirmation_loaded', function ( e, formId ) {
					fireFormSubmit( formId, 'Gravity Form ' + formId, 'gravity_forms' );
				} );
			}
		}

		/* WPForms */
		if ( isAuto || plugin === 'wpforms' ) {
			document.addEventListener( 'wpformsAjaxSubmitSuccess', function ( e ) {
				var form = e.target;
				var id   = form && form.getAttribute ? form.getAttribute( 'data-formid' ) : '';
				fireFormSubmit( id, 'WPForms ' + id, 'wpforms' );
			} );
		}

		/* Contact Form 7 */
		if ( isAuto || plugin === 'cf7' ) {
			document.addEventListener( 'wpcf7mailsent', function ( e ) {
				var detail = e.detail || {};
				var id     = detail.contactFormId || detail.id || '';
				fireFormSubmit( id, 'CF7 Form ' + id, 'cf7' );
			} );
		}

		/* Fluent Forms */
		if ( isAuto || plugin === 'fluentforms' ) {
			document.addEventListener( 'fluentform_submission_success', function ( e ) {
				var detail = e.detail || {};
				var form   = detail.form && detail.form[ 0 ] ? detail.form[ 0 ] : null;
				var id     = ( form && form.getAttribute( 'data-form_id' ) ) || '';
				fireFormSubmit( id, 'Fluent Form ' + ( id || '' ), 'fluent_forms' );
			} );
		}

		/* Formidable Forms */
		if ( isAuto || plugin === 'formidable' ) {
			document.addEventListener( 'frmFormComplete', function ( e ) {
				var detail = e.detail || {};
				var id     = detail.formId || '';
				fireFormSubmit( id, 'Formidable Form ' + id, 'formidable' );
			} );
		}

		/* Ninja Forms */
		if ( ( isAuto || plugin === 'ninja' ) && typeof window.nfRadio !== 'undefined' ) {
			try {
				window.nfRadio.channel( 'forms' ).on( 'submit:response', function ( response ) {
					var id = response && response.data ? response.data.form_id : '';
					fireFormSubmit( id, 'Ninja Form ' + id, 'ninja_forms' );
				} );
			} catch ( e ) {}
		}

		/* Elementor Pro — success is triggered through jQuery only. */
		if ( ( isAuto || plugin === 'elementor' ) && typeof jQuery !== 'undefined' ) {
			jQuery( document ).on( 'submit_success', '.elementor-form', function () {
				var form   = this;
				var hidden = form.querySelector( 'input[name="form_id"]' );
				var id     = ( hidden && hidden.value ) || form.getAttribute( 'name' ) || form.id || '';
				var title  = form.getAttribute( 'name' ) || form.getAttribute( 'aria-label' ) || ( id ? 'Elementor Form ' + id : 'Elementor Form' );
				fireFormSubmit( id, title, 'elementor' );
			} );
		}

		/* Generic HTML fallback — in Auto mode only for forms no plugin handles. */
		if ( isAuto || plugin === 'generic' ) {
			document.addEventListener( 'submit', function ( e ) {
				var form = e.target;
				if ( ! form || ! form.matches ) return;
				if ( form.matches( IGNORED_FORM_SELECTOR ) ) return;
				if ( isAuto && form.matches( KNOWN_FORM_SELECTOR ) ) return;

				var id    = form.id || form.getAttribute( 'name' ) || 'unknown';
				var title = form.getAttribute( 'aria-label' ) || form.getAttribute( 'data-title' ) || id;
				fireFormSubmit( id, title, 'generic' );
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

	/* ─── Link clicks: phone, email, downloads, outbound ───────────────────── */

	function initLinkTracking() {
		if ( ! cfg.trackPhone && ! cfg.trackEmail && ! cfg.trackDownloads && ! cfg.trackOutbound ) return;

		var extensions = ( cfg.downloadExtensions || [] ).map( function ( e ) { return String( e ).toLowerCase(); } );

		document.addEventListener( 'click', function ( e ) {
			if ( ! e.target || ! e.target.closest ) return;
			var link = e.target.closest( 'a[href]' );
			if ( ! link ) return;

			var href = link.getAttribute( 'href' ) || '';
			var text = clip( link.innerText || link.textContent, 100 );

			if ( /^tel:/i.test( href ) ) {
				if ( cfg.trackPhone ) {
					sendEvent( 'phone_click', { phone_number: clip( href.replace( /^tel:/i, '' ), 40 ), link_text: text } );
				}
				return;
			}

			if ( /^mailto:/i.test( href ) ) {
				// The address itself is not sent — PII is prohibited by Google's terms.
				if ( cfg.trackEmail ) {
					sendEvent( 'email_click', { link_text: text } );
				}
				return;
			}

			var url;
			try { url = new URL( link.href, location.href ); } catch ( err ) { return; }
			if ( ! /^https?:$/.test( url.protocol ) ) return;

			var file = url.pathname.split( '/' ).pop() || '';
			var ext  = file.indexOf( '.' ) !== -1 ? file.split( '.' ).pop().toLowerCase() : '';

			if ( cfg.trackDownloads && ext && extensions.indexOf( ext ) !== -1 ) {
				sendEvent( 'file_download', {
					file_name      : clip( decodeURIComponent( file ), 100 ),
					file_extension : ext,
					link_url       : clip( url.href, 500 ),
					link_text      : text,
				} );
				return;
			}

			if ( cfg.trackOutbound && url.hostname && ! isInternalHost( url.hostname ) ) {
				sendEvent( 'outbound_click', {
					link_url    : clip( url.href, 500 ),
					link_domain : stripWww( url.hostname ),
					link_text   : text,
				} );
			}
		} );
	}

	/* ─── Video engagement ──────────────────────────────────────────────────── */

	function initVideoTracking() {
		if ( ! cfg.trackVideo ) return;

		var MILESTONES = [ 25, 50, 75 ];
		var tracked    = typeof WeakSet === 'function' ? new WeakSet() : null;

		function seen( el ) {
			if ( ! tracked ) return false;
			if ( tracked.has( el ) ) return true;
			tracked.add( el );
			return false;
		}

		function makeState( provider, title, url ) {
			return { provider: provider, title: clip( title, 100 ), url: clip( url, 500 ), started: false, fired: {}, done: false };
		}

		function videoEvent( name, state, percent ) {
			sendEvent( name, {
				video_provider : state.provider,
				video_title    : state.title,
				video_url      : state.url,
				video_percent  : percent,
			} );
		}

		function progress( state, percent ) {
			if ( ! state.started && percent > 0 ) {
				state.started = true;
				videoEvent( 'video_start', state, 0 );
			}
			MILESTONES.forEach( function ( m ) {
				if ( percent >= m && ! state.fired[ m ] ) {
					state.fired[ m ] = true;
					videoEvent( 'video_progress', state, m );
				}
			} );
		}

		function complete( state ) {
			if ( state.done ) return;
			state.done = true;
			videoEvent( 'video_complete', state, 100 );
		}

		/* HTML5 <video> */
		function bindHtml5( el ) {
			if ( seen( el ) ) return;
			var state = makeState( 'html5', el.title || el.getAttribute( 'aria-label' ) || ( el.currentSrc || el.src || '' ).split( '/' ).pop(), el.currentSrc || el.src || '' );
			el.addEventListener( 'play', function () { progress( state, 0.01 ); state.started = true; } );
			el.addEventListener( 'timeupdate', function () {
				if ( el.duration ) progress( state, ( el.currentTime / el.duration ) * 100 );
			} );
			el.addEventListener( 'ended', function () { complete( state ); } );
		}

		/* YouTube via the IFrame Player API */
		var ytQueue = [];
		var ytLoading = false;

		function ytReady() {
			return window.YT && typeof window.YT.Player === 'function';
		}

		function loadYouTubeApi() {
			if ( ytReady() || ytLoading ) return;
			ytLoading = true;
			var prev = window.onYouTubeIframeAPIReady;
			window.onYouTubeIframeAPIReady = function () {
				if ( typeof prev === 'function' ) { try { prev(); } catch ( e ) {} }
				ytQueue.splice( 0 ).forEach( attachYouTube );
			};
			var s = document.createElement( 'script' );
			s.src = 'https://www.youtube.com/iframe_api';
			s.async = true;
			document.head.appendChild( s );
		}

		function attachYouTube( iframe ) {
			if ( ! ytReady() ) { ytQueue.push( iframe ); loadYouTubeApi(); return; }

			var state = makeState( 'youtube', iframe.title || 'YouTube video', iframe.src );
			var timer = null;

			function poll( player ) {
				try {
					var d = player.getDuration();
					if ( d ) progress( state, ( player.getCurrentTime() / d ) * 100 );
				} catch ( e ) {}
			}

			try {
				new window.YT.Player( iframe, {
					events: {
						onReady: function ( ev ) {
							try {
								var data = ev.target.getVideoData();
								if ( data && data.title ) state.title = clip( data.title, 100 );
								if ( data && data.video_id ) state.url = 'https://www.youtube.com/watch?v=' + data.video_id;
							} catch ( e ) {}
						},
						onStateChange: function ( ev ) {
							var YT = window.YT.PlayerState;
							if ( ev.data === YT.PLAYING ) {
								progress( state, 0.01 );
								if ( ! timer ) timer = setInterval( function () { poll( ev.target ); }, 1000 );
							} else if ( timer && ( ev.data === YT.PAUSED || ev.data === YT.ENDED ) ) {
								clearInterval( timer );
								timer = null;
							}
							if ( ev.data === YT.ENDED ) complete( state );
						},
					},
				} );
			} catch ( e ) {
				debug( 'YouTube player attach failed', e );
			}
		}

		function bindYouTube( iframe ) {
			if ( seen( iframe ) ) return;
			var src = iframe.getAttribute( 'src' ) || '';
			try {
				var u = new URL( src, location.href );
				// The player only reports playback when the JS API is enabled.
				if ( u.searchParams.get( 'enablejsapi' ) !== '1' || ! u.searchParams.get( 'origin' ) ) {
					u.searchParams.set( 'enablejsapi', '1' );
					u.searchParams.set( 'origin', location.origin );
					iframe.setAttribute( 'src', u.toString() );
				}
			} catch ( e ) { return; }
			attachYouTube( iframe );
		}

		/* Vimeo via its postMessage API */
		var vimeo = [];

		function bindVimeo( iframe ) {
			if ( seen( iframe ) ) return;
			var entry = { iframe: iframe, state: makeState( 'vimeo', iframe.title || 'Vimeo video', iframe.src ), ready: false };
			vimeo.push( entry );
			// Ask for events straight away; the player buffers requests until ready.
			vimeoSend( iframe, 'addEventListener', 'play' );
			vimeoSend( iframe, 'addEventListener', 'timeupdate' );
			vimeoSend( iframe, 'addEventListener', 'ended' );
			vimeoSend( iframe, 'getVideoTitle' );
		}

		function vimeoSend( iframe, method, value ) {
			try {
				var msg = { method: method };
				if ( value !== undefined ) msg.value = value;
				iframe.contentWindow.postMessage( JSON.stringify( msg ), '*' );
			} catch ( e ) {}
		}

		window.addEventListener( 'message', function ( e ) {
			if ( ! /vimeo\.com$/.test( ( function () { try { return new URL( e.origin ).hostname; } catch ( x ) { return ''; } } )() ) ) return;
			var data;
			try { data = typeof e.data === 'string' ? JSON.parse( e.data ) : e.data; } catch ( x ) { return; }
			if ( ! data ) return;

			for ( var i = 0; i < vimeo.length; i++ ) {
				var entry = vimeo[ i ];
				if ( entry.iframe.contentWindow !== e.source ) continue;

				if ( data.event === 'ready' && ! entry.ready ) {
					entry.ready = true;
					bindVimeoEvents( entry.iframe );
				} else if ( data.method === 'getVideoTitle' && data.value ) {
					entry.state.title = clip( data.value, 100 );
				} else if ( data.event === 'play' ) {
					progress( entry.state, 0.01 );
				} else if ( data.event === 'timeupdate' && data.data && typeof data.data.percent === 'number' ) {
					progress( entry.state, data.data.percent * 100 );
				} else if ( data.event === 'ended' || data.event === 'finish' ) {
					complete( entry.state );
				}
				break;
			}
		} );

		function bindVimeoEvents( iframe ) {
			vimeoSend( iframe, 'addEventListener', 'play' );
			vimeoSend( iframe, 'addEventListener', 'timeupdate' );
			vimeoSend( iframe, 'addEventListener', 'ended' );
			vimeoSend( iframe, 'getVideoTitle' );
		}

		/* Discovery — initial DOM plus anything a page builder injects later. */
		function scan( root ) {
			var scope = root && root.querySelectorAll ? root : document;
			scope.querySelectorAll( 'video' ).forEach( bindHtml5 );
			scope.querySelectorAll( 'iframe[src*="youtube.com/embed"], iframe[src*="youtube-nocookie.com/embed"]' ).forEach( bindYouTube );
			scope.querySelectorAll( 'iframe[src*="player.vimeo.com/video"]' ).forEach( bindVimeo );
		}

		scan( document );

		if ( typeof MutationObserver === 'function' ) {
			new MutationObserver( function ( mutations ) {
				mutations.forEach( function ( m ) {
					Array.prototype.forEach.call( m.addedNodes, function ( node ) {
						if ( node.nodeType !== 1 ) return;
						if ( node.matches && node.matches( 'video, iframe' ) ) scan( node.parentNode );
						else scan( node );
					} );
				} );
			} ).observe( document.documentElement, { childList: true, subtree: true } );
		}
	}

	/* ─── Init ──────────────────────────────────────────────────────────────── */

	initAttribution();

	function boot() {
		initCTATracking();
		initFormTracking();
		initScrollTracking();
		initLinkTracking();
		initVideoTracking();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}

} )();
