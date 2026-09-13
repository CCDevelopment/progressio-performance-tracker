/**
 * Progressio Performance Tracker — settings page behaviour.
 * Field-mapping repeater and the test-lead / retry-queue buttons.
 */
( function () {
	'use strict';

	var cfg = window.pptAdmin || {};

	/* ─── Field mapping repeater ───────────────────────────────────────────── */

	var rows     = document.getElementById( 'ppt-map-rows' );
	var template = document.getElementById( 'ppt-map-template' );
	var addBtn   = document.getElementById( 'ppt-map-add' );

	function nextIndex() {
		var max = -1;
		rows.querySelectorAll( 'tr' ).forEach( function ( tr ) {
			var input = tr.querySelector( '[name]' );
			var match = input && input.name.match( /\[field_map\]\[(\d+)\]/ );
			if ( match ) max = Math.max( max, parseInt( match[ 1 ], 10 ) );
		} );
		return max + 1;
	}

	if ( rows && template && addBtn ) {
		addBtn.addEventListener( 'click', function () {
			var index = nextIndex();
			var html  = template.innerHTML.replace( /\[field_map\]\[9999\]/g, '[field_map][' + index + ']' );
			rows.insertAdjacentHTML( 'beforeend', html );
			var first = rows.lastElementChild.querySelector( 'select' );
			if ( first ) first.focus();
		} );

		rows.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.ppt-map-remove' );
			if ( ! btn ) return;
			var tr = btn.closest( 'tr' );
			if ( tr ) tr.remove();
		} );
	}

	/* ─── AJAX actions ─────────────────────────────────────────────────────── */

	var result = document.getElementById( 'ppt-action-result' );

	function post( action, data, busyText, btn ) {
		if ( ! result || ! cfg.ajaxUrl ) return;

		var body = new URLSearchParams( Object.assign( { action: action, nonce: cfg.nonce }, data || {} ) );
		result.className = '';
		result.textContent = busyText;
		if ( btn ) btn.disabled = true;

		fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( json ) {
				var ok = json && json.success;
				result.className = ok ? 'is-ok' : 'is-error';
				result.textContent = ( json && json.data && json.data.message ) || ( ok ? 'OK' : cfg.i18n.failed );
			} )
			.catch( function () {
				result.className = 'is-error';
				result.textContent = cfg.i18n.failed;
			} )
			.then( function () {
				if ( btn ) btn.disabled = false;
			} );
	}

	var testBtn = document.getElementById( 'ppt-test-lead' );
	if ( testBtn ) {
		testBtn.addEventListener( 'click', function () {
			post( 'ppt_test_lead', {}, cfg.i18n.sending, testBtn );
		} );
	}

	var queueBtn = document.getElementById( 'ppt-process-queue' );
	if ( queueBtn ) {
		queueBtn.addEventListener( 'click', function () {
			post( 'ppt_process_queue', {}, cfg.i18n.processing, queueBtn );
		} );
	}
} )();
