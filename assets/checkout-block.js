/**
 * Registers the off-site payment method with WooCommerce's block checkout.
 *
 * THIS FILE IS DELIVERED TO BUYERS, so every word in it — comments included —
 * is text a buyer can read in view-source. Nothing here may name the supplier,
 * and that includes file names. The reason this file has to exist at all is
 * written in the plugin's own PHP, which buyers never receive.
 *
 * It is a label and a sentence. No payment logic and no network call: the
 * server decides everything and the block follows the redirect it returns.
 */
( function ( wc, wcSettings, element, htmlEntities ) {
	if ( ! wc || ! wc.registerPaymentMethod || ! wcSettings || ! element ) {
		return;
	}

	var NAME = 'secure_checkout';
	var data = wcSettings.getSetting( NAME + '_data', {} );
	var decode = htmlEntities && htmlEntities.decodeEntities ? htmlEntities.decodeEntities : function ( s ) { return s; };
	var title = decode( data.title || 'Secure checkout' );
	var description = decode( data.description || '' );

	function Description() {
		return description ? element.createElement( 'p', null, description ) : null;
	}

	wc.registerPaymentMethod( {
		name: NAME,
		label: title,
		ariaLabel: title,
		content: element.createElement( Description ),
		edit: element.createElement( Description ),
		canMakePayment: function () {
			return true;
		},
		supports: {
			features: data.supports || [ 'products' ],
		},
	} );
} )( window.wc && window.wc.wcBlocksRegistry, window.wc && window.wc.wcSettings, window.wp && window.wp.element, window.wp && window.wp.htmlEntities );
