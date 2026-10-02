/* RSS Media Hub – progressive enhancements. No dependencies; every feature works without JS. */
( function () {
	'use strict';

	var cfg = window.RMH || { i18n: {} };
	var t = function ( k, d ) { return ( cfg.i18n && cfg.i18n[ k ] ) || d || k; };
	document.documentElement.classList.add( 'js-rmh' );

	function setStatus( el, text, isError ) {
		if ( ! el ) { return; }
		el.textContent = text;
		el.style.color = isError ? 'var(--rmh-danger)' : '';
	}

	/* ---------- Lightbox (keyboard accessible dialog) ---------- */
	function initLightbox() {
		var links = Array.prototype.slice.call( document.querySelectorAll( '.rmh-lightbox-link' ) );
		if ( ! links.length ) { return; }
		var box = document.createElement( 'div' );
		box.className = 'rmh-lightbox';
		box.setAttribute( 'role', 'dialog' );
		box.setAttribute( 'aria-modal', 'true' );
		box.setAttribute( 'aria-label', document.title );
		box.hidden = true;
		box.innerHTML = '<div class="rmh-lightbox-bar"><span class="rmh-lightbox-count" aria-live="polite"></span><button type="button" class="rmh-lb-close"></button></div>' +
			'<img alt="">' +
			'<div class="rmh-lightbox-bar"><button type="button" class="rmh-lb-prev"></button><button type="button" class="rmh-lb-next"></button></div>';
		document.body.appendChild( box );
		var img = box.querySelector( 'img' );
		var count = box.querySelector( '.rmh-lightbox-count' );
		var closeBtn = box.querySelector( '.rmh-lb-close' );
		var prevBtn = box.querySelector( '.rmh-lb-prev' );
		var nextBtn = box.querySelector( '.rmh-lb-next' );
		closeBtn.textContent = '×';
		closeBtn.setAttribute( 'aria-label', t( 'close', 'Close' ) );
		prevBtn.textContent = '←';
		prevBtn.setAttribute( 'aria-label', t( 'prev', 'Previous image' ) );
		nextBtn.textContent = '→';
		nextBtn.setAttribute( 'aria-label', t( 'next', 'Next image' ) );
		var index = 0;
		var opener = null;

		function show( i ) {
			index = ( i + links.length ) % links.length;
			var a = links[ index ];
			var thumb = a.querySelector( 'img' );
			img.src = a.getAttribute( 'href' );
			img.alt = thumb ? thumb.alt : '';
			img.referrerPolicy = 'no-referrer';
			count.textContent = ( index + 1 ) + ' / ' + links.length;
			prevBtn.hidden = nextBtn.hidden = links.length < 2;
		}
		function open( i, el ) {
			opener = el;
			show( i );
			box.hidden = false;
			document.body.style.overflow = 'hidden';
			closeBtn.focus();
		}
		function close() {
			box.hidden = true;
			img.removeAttribute( 'src' );
			document.body.style.overflow = '';
			if ( opener ) { opener.focus(); }
		}
		links.forEach( function ( a, i ) {
			a.addEventListener( 'click', function ( e ) {
				if ( e.metaKey || e.ctrlKey || e.shiftKey ) { return; }
				e.preventDefault();
				open( i, a );
			} );
		} );
		closeBtn.addEventListener( 'click', close );
		prevBtn.addEventListener( 'click', function () { show( index - 1 ); } );
		nextBtn.addEventListener( 'click', function () { show( index + 1 ); } );
		box.addEventListener( 'click', function ( e ) { if ( e.target === box || e.target === img ) { close(); } } );
		box.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key ) { close(); }
			else if ( 'ArrowLeft' === e.key ) { show( index - 1 ); }
			else if ( 'ArrowRight' === e.key ) { show( index + 1 ); }
			else if ( 'Tab' === e.key ) {
				var f = Array.prototype.filter.call( box.querySelectorAll( 'button' ), function ( b ) { return ! b.hidden; } );
				var first = f[ 0 ], last = f[ f.length - 1 ];
				if ( e.shiftKey && document.activeElement === first ) { e.preventDefault(); last.focus(); }
				else if ( ! e.shiftKey && document.activeElement === last ) { e.preventDefault(); first.focus(); }
			}
		} );
	}

	/* ---------- Load more (with loading and error states) ---------- */
	function initLoadMore() {
		var btn = document.querySelector( '.rmh-loadmore' );
		var grid = document.querySelector( '[data-rmh-results]' );
		if ( ! btn || ! grid || ! window.fetch || ! window.DOMParser ) { return; }
		var status = btn.parentNode.querySelector( '.rmh-form-status' );
		var pager = document.querySelector( '.rmh-pagination' );
		var current = parseInt( btn.getAttribute( 'data-current' ), 10 );
		var total = parseInt( btn.getAttribute( 'data-total' ), 10 );
		var next = btn.getAttribute( 'data-next' );
		btn.hidden = false;
		if ( pager ) { pager.classList.add( 'rmh-has-loadmore' ); }

		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			grid.classList.add( 'rmh-loading' );
			grid.setAttribute( 'aria-busy', 'true' );
			setStatus( status, t( 'loading', 'Loading…' ) );
			fetch( next, { credentials: 'same-origin', headers: { 'X-Requested-With': 'rmh-loadmore' } } )
				.then( function ( r ) { if ( ! r.ok ) { throw new Error( r.status ); } return r.text(); } )
				.then( function ( html ) {
					var doc = new DOMParser().parseFromString( html, 'text/html' );
					var cards = doc.querySelectorAll( '[data-rmh-results] > .rmh-card' );
					var firstNew = null;
					Array.prototype.forEach.call( cards, function ( c ) {
						var node = document.importNode( c, true );
						grid.appendChild( node );
						if ( ! firstNew ) { firstNew = node; }
					} );
					current += 1;
					var nb = doc.querySelector( '.rmh-loadmore' );
					next = nb ? nb.getAttribute( 'data-next' ) : null;
					setStatus( status, '' );
					if ( firstNew ) {
						var link = firstNew.querySelector( 'a' );
						if ( link ) { link.focus( { preventScroll: true } ); }
					}
					if ( ! next || current >= total ) { btn.hidden = true; if ( pager ) { pager.classList.remove( 'rmh-has-loadmore' ); } }
				} )
				.catch( function () { setStatus( status, t( 'loadError', 'Could not load more items.' ), true ); } )
				.then( function () {
					btn.disabled = false;
					grid.classList.remove( 'rmh-loading' );
					grid.removeAttribute( 'aria-busy' );
				} );
		} );
	}

	/* ---------- Report form ---------- */
	function initReports() {
		Array.prototype.forEach.call( document.querySelectorAll( 'form[data-rmh-report]' ), function ( form ) {
			if ( ! window.fetch || ! window.FormData ) { return; }
			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var status = form.querySelector( '.rmh-form-status' );
				var button = form.querySelector( 'button[type="submit"]' );
				button.disabled = true;
				setStatus( status, t( 'sending', 'Sending…' ) );
				// getAttribute(): the form contains <input name="action">, which shadows form.action.
				fetch( form.getAttribute( 'action' ), { method: 'POST', body: new FormData( form ), credentials: 'same-origin', headers: { Accept: 'application/json' } } )
					.then( function ( r ) { return r.json().then( function ( j ) { return { ok: r.ok && j && j.success, j: j }; } ); } )
					.then( function ( res ) {
						if ( res.ok ) { form.reset(); setStatus( status, t( 'sent', 'Thank you.' ) ); }
						else { setStatus( status, ( res.j && res.j.data && res.j.data.message ) || t( 'sendError', 'Error' ), true ); }
					} )
					.catch( function () { setStatus( status, t( 'sendError', 'Error' ), true ); } )
					.then( function () { button.disabled = false; } );
			} );
		} );
	}

	/* ---------- Copy link ---------- */
	function initCopy() {
		Array.prototype.forEach.call( document.querySelectorAll( '.rmh-copy-link' ), function ( b ) {
			b.addEventListener( 'click', function () {
				var url = b.getAttribute( 'data-url' );
				var done = function () { var old = b.textContent; b.textContent = t( 'copied', 'Copied' ); setTimeout( function () { b.textContent = old; }, 1800 ); };
				if ( navigator.clipboard && navigator.clipboard.writeText ) { navigator.clipboard.writeText( url ).then( done ); }
				else { window.prompt( '', url ); }
			} );
		} );
	}

	/* ---------- Privacy-conscious view counter (opt-in) ---------- */
	function initViews() {
		var main = document.querySelector( '.rmh-single[data-rmh-post]' );
		if ( ! cfg.analytics || ! main || ! window.fetch ) { return; }
		var id = main.getAttribute( 'data-rmh-post' );
		try {
			var key = 'rmh_v_' + id;
			if ( window.sessionStorage.getItem( key ) ) { return; }
			window.sessionStorage.setItem( key, '1' );
		} catch ( e ) { /* storage unavailable: still count once per page view */ }
		fetch( cfg.restUrl + 'views/' + encodeURIComponent( id ), { method: 'POST', credentials: 'omit', keepalive: true } ).catch( function () {} );
	}

	/* ---------- Overlay age gate (cache-friendly deterrent; not verification) ---------- */
	function initOverlayGate() {
		if ( ! cfg.ageGate ) { return; }
		var ok = false;
		try { ok = '1' === window.localStorage.getItem( 'rmh_age_ok' ); } catch ( e ) { ok = /(?:^|; )rmh_age_overlay=1/.test( document.cookie ); }
		if ( ok ) { return; }
		document.body.classList.add( 'rmh-gated' );
		var o = document.createElement( 'div' );
		o.className = 'rmh-overlay';
		o.setAttribute( 'role', 'dialog' );
		o.setAttribute( 'aria-modal', 'true' );
		o.setAttribute( 'aria-labelledby', 'rmh-ov-title' );
		var box = document.createElement( 'div' );
		box.className = 'rmh-agegate-box';
		var h = document.createElement( 'h2' );
		h.id = 'rmh-ov-title';
		h.textContent = t( 'gateTitle', 'Adults only' );
		var p = document.createElement( 'p' );
		p.textContent = t( 'gateText', '' );
		var actions = document.createElement( 'p' );
		actions.className = 'rmh-agegate-actions';
		var enter = document.createElement( 'button' );
		enter.type = 'button';
		enter.className = 'rmh-button';
		enter.textContent = t( 'gateEnter', 'Enter' );
		var leave = document.createElement( 'a' );
		leave.className = 'rmh-button rmh-button--secondary';
		leave.href = 'https://www.google.com/';
		leave.textContent = t( 'gateLeave', 'Leave' );
		actions.appendChild( enter );
		actions.appendChild( leave );
		box.appendChild( h );
		box.appendChild( p );
		box.appendChild( actions );
		o.appendChild( box );
		document.body.appendChild( o );
		enter.focus();
		o.addEventListener( 'keydown', function ( e ) {
			if ( 'Tab' === e.key ) {
				e.preventDefault();
				( document.activeElement === enter ? leave : enter ).focus();
			}
		} );
		enter.addEventListener( 'click', function () {
			try { window.localStorage.setItem( 'rmh_age_ok', '1' ); } catch ( e ) { document.cookie = 'rmh_age_overlay=1; path=/; max-age=2592000; samesite=lax'; }
			o.parentNode.removeChild( o );
			document.body.classList.remove( 'rmh-gated' );
		} );
	}

	function init() {
		initOverlayGate();
		initLightbox();
		initLoadMore();
		initReports();
		initCopy();
		initViews();
	}
	if ( 'loading' === document.readyState ) { document.addEventListener( 'DOMContentLoaded', init ); } else { init(); }
}() );
