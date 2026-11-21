/* Thank-you page: reload every 5 s (up to 2 minutes) until CasaPay has confirmed the payment. */
( function () {
	var box = document.querySelector( '.casapay-wc-processing[data-order-id]' );
	if ( ! box ) {
		return;
	}
	try {
		var key = 'casapay_reload_' + box.getAttribute( 'data-order-id' );
		var count = Number( window.sessionStorage.getItem( key ) || 0 );
		if ( count < 24 ) {
			window.sessionStorage.setItem( key, String( count + 1 ) );
			window.setTimeout( function () {
				window.location.reload();
			}, 5000 );
		}
	} catch ( e ) {}
} )();
