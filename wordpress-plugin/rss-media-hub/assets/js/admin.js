/* RSS Media Hub admin enhancements. */
( function () {
	'use strict';
	var cfg = window.RMHAdmin || {};

	// Confirm destructive actions.
	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		var msg = form.getAttribute && form.getAttribute( 'data-rmh-confirm' );
		if ( ! msg ) { return; }
		var ops = form.getAttribute( 'data-rmh-confirm-ops' );
		if ( ops ) {
			var sel = form.querySelector( 'select[name="op"]' );
			if ( ! sel || ops.split( ',' ).indexOf( sel.value ) === -1 ) { return; }
		}
		if ( ! window.confirm( msg ) ) { e.preventDefault(); }
	}, true );

	// Select all.
	Array.prototype.forEach.call( document.querySelectorAll( '.rmh-check-all' ), function ( box ) {
		box.addEventListener( 'change', function () {
			var table = box.closest( 'table' );
			Array.prototype.forEach.call( table.querySelectorAll( 'input[name="ids[]"]' ), function ( c ) { c.checked = box.checked; } );
		} );
	} );

	// Custom polling interval toggle.
	var interval = document.getElementById( 'rmh-interval' );
	var custom = document.querySelector( '.rmh-custom-interval' );
	if ( interval && custom ) {
		var sync = function () { custom.style.display = 'custom' === interval.value ? '' : 'none'; };
		interval.addEventListener( 'change', sync );
		sync();
	}

	// Copy diagnostic report.
	Array.prototype.forEach.call( document.querySelectorAll( '.rmh-copy' ), function ( b ) {
		b.addEventListener( 'click', function () {
			var ta = document.getElementById( b.getAttribute( 'data-target' ) );
			var status = b.parentNode.querySelector( '.rmh-copy-status' );
			var done = function () { if ( status ) { status.textContent = cfg.copied || 'Copied'; } };
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( ta.value ).then( done, function () { ta.select(); document.execCommand( 'copy' ); done(); } );
			} else { ta.select(); document.execCommand( 'copy' ); done(); }
		} );
	} );
}() );
