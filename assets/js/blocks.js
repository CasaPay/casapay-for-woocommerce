/* CasaPay payment method for the WooCommerce Checkout block. No build step needed. */
( function () {
	const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
	const { getSetting } = window.wc.wcSettings;
	const { createElement: el, useState } = window.wp.element;
	const { decodeEntities } = window.wp.htmlEntities;

	const data = getSetting( 'casapay_data', {} );
	const title = decodeEntities( data.title || 'CasaPay' );

	const Content = ( props ) => {
		const ext = ( props.cartData && props.cartData.extensions && props.cartData.extensions.casapay ) || {};
		const options = ext.options || [];
		const [ busy, setBusy ] = useState( false );

		const choose = ( mode ) => {
			if ( busy || mode === ext.selected ) {
				return;
			}
			setBusy( true );
			// Recalculates the cart on the server; the fee line and total update in the order summary.
			Promise.resolve(
				window.wc.blocksCheckout.extensionCartUpdate( { namespace: 'casapay', data: { deposit_mode: mode } } )
			).finally( () => setBusy( false ) );
		};

		return el(
			'div',
			{ className: 'casapay-wc' },
			data.description ? el( 'p', null, decodeEntities( data.description ) ) : null,
			options.length
				? el(
						'fieldset',
						{ className: 'casapay-wc-options', disabled: busy, style: { border: 0, padding: 0, margin: 0 } },
						el( 'legend', { style: { fontWeight: 600, marginBottom: '8px' } }, decodeEntities( data.legend || '' ) ),
						options.map( ( o ) =>
							el(
								'label',
								{ key: o.mode, style: { display: 'flex', gap: '8px', alignItems: 'flex-start', marginBottom: '8px', cursor: 'pointer' } },
								el( 'input', {
									type: 'radio',
									name: 'casapay_deposit_mode',
									value: o.mode,
									checked: ext.selected === o.mode,
									onChange: () => choose( o.mode ),
								} ),
								el( 'span', null, el( 'strong', null, o.label ), el( 'br' ), el( 'small', null, o.summary ) )
							)
						)
				  )
				: null
		);
	};

	registerPaymentMethod( {
		name: 'casapay',
		label: el( 'span', null, title ),
		ariaLabel: title,
		content: el( Content ),
		edit: el( Content ),
		canMakePayment: () => true,
		supports: { features: data.supports || [ 'products' ] },
	} );
} )();
