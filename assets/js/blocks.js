/* Coino Live in the WooCommerce block checkout (no build step: plain WordPress globals). */
( function () {
	var settings = window.wc.wcSettings.getSetting( 'coinolive_data', {} );
	var el = window.wp.element.createElement;
	var decode = window.wp.htmlEntities.decodeEntities;
	var title = decode( settings.title || 'Pay with crypto' );

	function Content() {
		return el( 'div', null, decode( settings.description || '' ) );
	}

	function Label( props ) {
		var Text = props.components.PaymentMethodLabel;
		return el(
			'span',
			{ style: { display: 'inline-flex', alignItems: 'center', gap: '8px' } },
			settings.icon ? el( 'img', { src: settings.icon, alt: '', width: 24, height: 24 } ) : null,
			el( Text, { text: title } )
		);
	}

	window.wc.wcBlocksRegistry.registerPaymentMethod( {
		name: 'coinolive',
		label: el( Label ),
		content: el( Content ),
		edit: el( Content ),
		canMakePayment: function () {
			return true;
		},
		ariaLabel: title,
		supports: { features: settings.supports || [ 'products' ] },
	} );
} )();
