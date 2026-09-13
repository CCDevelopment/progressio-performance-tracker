// Runs tracker.js under Node with a minimal DOM stub to exercise attribution
// and event dispatch. Usage: node js-harness.js /path/to/tracker.js
const fs = require( 'fs' );
const vm = require( 'vm' );

const src = fs.readFileSync( process.argv[ 2 ] || require( 'path' ).join( __dirname, '..', '..', 'assets', 'tracker.js' ), 'utf8' );
let fail = 0;
const ok = ( c, m ) => { console.log( ( c ? 'PASS ' : 'FAIL ' ) + m ); if ( ! c ) fail++; };

function makeEnv( { url, referrer, cookies = {}, gtag = true } ) {
	const jar = { ...cookies };
	const events = [];
	const listeners = {};
	const loc = new URL( url );
	const document = {
		referrer,
		readyState: 'complete',
		title: 'Test',
		get cookie() { return Object.entries( jar ).map( ( [ k, v ] ) => k + '=' + v ).join( '; ' ); },
		set cookie( str ) {
			const [ pair, ...attrs ] = str.split( ';' ).map( ( s ) => s.trim() );
			const [ k, v ] = pair.split( '=' );
			const maxAge = attrs.find( ( a ) => /^Max-Age=/i.test( a ) );
			if ( maxAge && parseInt( maxAge.split( '=' )[ 1 ], 10 ) < 0 ) delete jar[ k ]; else jar[ k ] = v;
			jar._lastAttrs = attrs.join( ';' );
		},
		addEventListener( n, cb ) { ( listeners[ n ] = listeners[ n ] || [] ).push( cb ); },
		querySelector() { return null; },
		querySelectorAll() { return []; },
		documentElement: { scrollHeight: 2000 },
		head: { appendChild() {} },
		createElement() { return {}; },
	};
	const window = {
		pptConfig: { siteDomain: 'example.com', attributionDays: 30, debugMode: false, trackForms: true, formPlugin: 'auto', trackScroll: true, trackOutbound: true, trackPhone: true, trackEmail: true, trackDownloads: true, trackVideo: true, downloadExtensions: [ 'pdf' ], ctaClasses: [ { cssClass: 'btn--action', label: 'Primary CTA', tier: 'primary' } ], leadsEnabled: true },
		location: loc,
		document,
		addEventListener( n, cb ) { ( listeners[ 'w:' + n ] = listeners[ 'w:' + n ] || [] ).push( cb ); },
		crypto: require( 'crypto' ).webcrypto,
		URL, URLSearchParams,
		CSS: { escape: ( s ) => s },
		console,
		dataLayer: [],
	};
	if ( gtag ) window.gtag = ( ...args ) => events.push( args );
	window.window = window;
	const ctx = vm.createContext( { window, document, location: loc, URL, URLSearchParams, console, requestAnimationFrame: ( f ) => f(), setInterval() {}, clearInterval() {}, Date, JSON, Object, Array, String, Math, RegExp, Uint8Array, WeakSet, MutationObserver: undefined, fetch: undefined, jQuery: undefined } );
	vm.runInContext( src, ctx );
	return { jar, events, listeners, window, document, fire: ( n, ev ) => ( listeners[ n ] || [] ).forEach( ( cb ) => cb( ev ) ) };
}

const parse = ( v ) => JSON.parse( decodeURIComponent( v ) );

// 1. Google Ads auto-tagged landing: gclid only.
let env = makeEnv( { url: 'https://www.example.com/landing/?gclid=ABC123&session=SECRET', referrer: 'https://www.google.com/' } );
let ft = parse( env.jar.ppt_ft ), lt = parse( env.jar.ppt_lt );
ok( ft.g === 'ABC123' && ft.s === 'google' && ft.m === 'cpc' && ft.ch === 'Paid Search', 'gclid-only landing attributed as Paid Search: ' + JSON.stringify( ft ) );
ok( ft.lp === '/landing/?gclid=ABC123', 'landing page keeps only marketing params: ' + ft.lp );
ok( /^[0-9a-f-]{36}$/.test( ft.v ), 'visitor id generated' );
ok( /Secure/.test( env.jar._lastAttrs ) && /SameSite=Lax/.test( env.jar._lastAttrs ), 'cookie flags: ' + env.jar._lastAttrs );
ok( lt.g === 'ABC123', 'last touch mirrors first on first visit' );

// 2. Internal navigation on a www site with existing cookies: no new touch.
const cookies = { ppt_ft: env.jar.ppt_ft, ppt_lt: env.jar.ppt_lt };
env = makeEnv( { url: 'https://www.example.com/pricing/', referrer: 'https://example.com/landing/', cookies } );
ok( env.jar.ppt_lt === cookies.ppt_lt, 'www ↔ apex referrer treated as internal (last touch unchanged)' );

// 3. New session (no lt cookie), direct visit: lt becomes Direct, ft kept, traffic_* falls back to first touch.
env = makeEnv( { url: 'https://www.example.com/', referrer: '', cookies: { ppt_ft: cookies.ppt_ft } } );
lt = parse( env.jar.ppt_lt );
ok( lt.ch === 'Direct' && ! lt.s, 'new direct session → Direct last touch' );
ok( parse( env.jar.ppt_ft ).v === ft.v, 'first touch retained across sessions' );
// Fire a phone click to inspect attribution params.
const el = ( o ) => ( { closest: () => null, matches: () => false, ...o } );
const link = el( { getAttribute: () => "tel:+15550100", innerText: "Call", href: "tel:+15550100" } );
env.fire( 'click', { target: { closest: () => link } } );
let ev = env.events.find( ( e ) => e[ 1 ] === 'phone_click' );
ok( !! ev, 'phone_click fired' );
ok( ev && ev[ 2 ].traffic_source === 'google' && ev[ 2 ].traffic_channel === 'Paid Search' && ev[ 2 ].first_channel === 'Paid Search' && ev[ 2 ].landing_page === '/landing/?gclid=ABC123' && ev[ 2 ].ppt_visitor_id === ft.v, 'attribution params: ' + JSON.stringify( ev && ev[ 2 ] ) );

// 4. New session from a t.co referrer → Organic Social last touch, first touch untouched.
env = makeEnv( { url: 'https://www.example.com/blog/post/', referrer: 'https://t.co/xyz', cookies: { ppt_ft: cookies.ppt_ft } } );
lt = parse( env.jar.ppt_lt );
ok( lt.s === 't.co' && lt.m === 'referral' && lt.ch === 'Organic Social', 'twitter referrer → Organic Social: ' + JSON.stringify( lt ) );
ok( parse( env.jar.ppt_ft ).g === 'ABC123', 'first touch not overwritten by later touch' );

// 5. UTM landing with fbclid.
env = makeEnv( { url: 'https://www.example.com/?utm_source=newsletter&utm_medium=email&utm_campaign=sept&fbclid=F1', referrer: '' } );
ft = parse( env.jar.ppt_ft );
ok( ft.s === 'newsletter' && ft.m === 'email' && ft.c === 'sept' && ft.f === 'F1', 'utm + click id captured' );
ok( ft.ch === 'Paid Social', 'click id beats medium in classification (' + ft.ch + ')' );

// 6. Form submit dedupe + known-plugin skip + generate_lead cookie consumption.
env = makeEnv( { url: 'https://www.example.com/contact/', referrer: '', cookies: { ppt_last_lead: 'lead_42' } } );
// The pending lead cookie from a non-AJAX submission is consumed at init.
ok( env.events.some( ( e ) => e[ 1 ] === 'generate_lead' && e[ 2 ].lead_id === 'lead_42' ), 'generate_lead from pending cookie at init' );
ok( ! env.jar.ppt_last_lead, 'lead cookie deleted after use' );
env.events.length = 0;
const cf7Form = { matches: ( s ) => s.includes( '.wpcf7-form' ), id: 'wpcf7-f1-o1', getAttribute: () => null };
env.fire( 'submit', { target: cf7Form } );
ok( env.events.length === 0, 'generic handler skips CF7 form in auto mode' );
env.fire( 'wpcf7mailsent', { detail: { contactFormId: 1 } } );
env.fire( 'wpcf7mailsent', { detail: { contactFormId: 1 } } );
ok( env.events.filter( ( e ) => e[ 1 ] === 'form_submit' ).length === 1, 'duplicate success within 1.5s deduped' );
const plainForm = { matches: ( s ) => false, id: 'custom', getAttribute: () => null };
env.fire( 'submit', { target: { ...plainForm, matches: () => false } } );
ok( env.events.filter( ( e ) => e[ 1 ] === 'form_submit' ).length === 1, 'generic form within dedupe window suppressed (expected)' );
const searchForm = { matches: ( s ) => s.includes( 'role="search"' ), id: 's', getAttribute: () => null };
env.events.length = 0;
env.fire( 'submit', { target: searchForm } );
ok( env.events.length === 0, 'search form ignored' );

// 7. Downloads vs outbound.
env = makeEnv( { url: 'https://www.example.com/', referrer: '' } );
const pdf = el( { getAttribute: () => '/files/brochure.pdf', href: 'https://www.example.com/files/brochure.pdf', innerText: "Brochure" } );
env.fire( 'click', { target: { closest: () => pdf } } );
ev = env.events.find( ( e ) => e[ 1 ] === 'file_download' );
ok( ev && ev[ 2 ].file_extension === 'pdf' && ev[ 2 ].file_name === 'brochure.pdf', 'file_download: ' + JSON.stringify( ev && ev[ 2 ] ) );
ok( ! env.events.find( ( e ) => e[ 1 ] === 'outbound_click' ), 'internal pdf not outbound' );
const ext = el( { getAttribute: () => 'https://partner.org/x', href: 'https://partner.org/x', innerText: "Partner" } );
env.fire( 'click', { target: { closest: () => ext } } );
ev = env.events.find( ( e ) => e[ 1 ] === 'outbound_click' );
ok( ev && ev[ 2 ].link_domain === 'partner.org', 'outbound_click' );
const sub = el( { getAttribute: () => 'https://example.com/about', href: 'https://example.com/about', innerText: "About" } );
env.events.length = 0;
env.fire( 'click', { target: { closest: () => sub } } );
ok( env.events.length === 0, 'apex-domain link on www site is internal' );

// 8. CTA via data attribute and via class.
env = makeEnv( { url: 'https://www.example.com/', referrer: '' } );
const attrEl = { getAttribute: ( a ) => a === 'data-ppt-cta' ? 'secondary' : 'Book now', className: 'x' };
const ctrl = { closest: ( s ) => s.includes( 'data-ppt-cta' ) ? attrEl : null, innerText: 'Book', href: '/book' };
env.fire( 'click', { target: { closest: ( s ) => s.startsWith( 'a,' ) ? ctrl : null } } );
ev = env.events.find( ( e ) => e[ 1 ] === 'cta_click' );
ok( ev && ev[ 2 ].cta_tier === 'secondary' && ev[ 2 ].cta_label === 'Book now', 'data-ppt-cta: ' + JSON.stringify( ev && ev[ 2 ] ) );
env.events.length = 0;
const classEl = { matches: ( s ) => s === '.btn--action', className: 'btn btn--action' };
const ctrl2 = { closest: ( s ) => s.includes( 'data-ppt-cta' ) ? null : classEl, innerText: 'Go', href: '/go' };
env.fire( 'click', { target: { closest: ( s ) => s.startsWith( 'a,' ) ? ctrl2 : null } } );
ev = env.events.find( ( e ) => e[ 1 ] === 'cta_click' );
ok( ev && ev[ 2 ].cta_tier === 'primary' && ev[ 2 ].cta_label === 'Primary CTA', 'class CTA: ' + JSON.stringify( ev && ev[ 2 ] ) );

// 9. No gtag yet → dataLayer queue.
env = makeEnv( { url: 'https://www.example.com/', referrer: '', gtag: false } );
env.fire( 'click', { target: { closest: () => link } } );
ok( env.window.dataLayer.length === 1 && env.window.dataLayer[ 0 ][ 0 ] === 'event' && env.window.dataLayer[ 0 ][ 1 ] === 'phone_click', 'events queued to dataLayer when gtag absent' );

console.log( '\n' + ( fail ? fail + ' FAILURE(S)' : 'ALL PASSED' ) );
process.exit( fail ? 1 : 0 );
