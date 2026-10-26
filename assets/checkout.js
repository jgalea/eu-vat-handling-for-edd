jQuery( function ( $ ) {
	const DEBOUNCE_MS = 600;
	let timer = null;
	let pending = null;

	function country() {
		return $( '#edd_cc_address #billing_country' ).val() || '';
	}

	/**
	 * Sends what changed, shows the notice for the decision that follows and,
	 * unless EDD has just recalculated, has EDD recalculate the cart.
	 */
	function check( data, recalculate ) {
		const $wrap = $( '#vatright-edd-vat' );
		if ( ! $wrap.length ) {
			return;
		}
		if ( pending ) {
			pending.abort();
		}
		pending = $.post(
			$wrap.data( 'ajax-url' ),
			$.extend(
				{
					action: 'vatright_edd_check',
					nonce: $wrap.data( 'nonce' ),
					billing_country: country(),
					card_zip: $( '#edd_cc_address #card_zip' ).val() || '',
				},
				data
			)
		).done( function ( response ) {
			if ( ! response || ! response.success ) {
				return;
			}
			$( '#vatright-edd-notice' ).html( response.data.html );
			if ( recalculate && 'function' === typeof window.recalculate_taxes ) {
				window.recalculate_taxes();
			}
		} ).always( function () {
			pending = null;
		} );
	}

	$( document.body ).on( 'input', '#vatright-edd-vat-number', function () {
		const value = this.value;
		clearTimeout( timer );
		timer = setTimeout( function () {
			check( { vatright_edd_vat_number: value }, true );
		}, DEBOUNCE_MS );
	} );

	$( document.body ).on( 'change', '#vatright-edd-self-certify', function () {
		check( { vatright_edd_self_certify_field: '1', vatright_edd_self_certify: this.checked ? '1' : '' }, true );
	} );

	// EDD recalculates the cart itself on a country change, and the server drops the number given for the old country.
	$( document.body ).on( 'change', '#edd_cc_address #billing_country', function () {
		clearTimeout( timer );
		$( '#vatright-edd-vat-number' ).val( '' );
		$( '#vatright-edd-notice' ).empty();
	} );

	$( document.body ).on( 'edd_taxes_recalculated', function () {
		check( {}, false );
	} );

	// A number remembered from the customer's last order is only filled in; checking it is what applies it.
	const $prefilled = $( '#vatright-edd-vat[data-prefilled]' );
	if ( $prefilled.length ) {
		check( { vatright_edd_vat_number: $( '#vatright-edd-vat-number' ).val() }, true );
	}
} );
