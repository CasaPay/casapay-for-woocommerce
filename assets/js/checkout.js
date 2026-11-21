/* Classic checkout: recalculate totals when the customer changes the deposit option. */
jQuery( function ( $ ) {
	$( document.body ).on( 'change', 'input[name="casapay_deposit_mode"]', function () {
		$( document.body ).trigger( 'update_checkout' );
	} );
} );
