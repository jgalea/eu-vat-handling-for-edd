jQuery( function ( $ ) {
	const DEBOUNCE_MS = 600;
	let timer = null;
	let pending = null;

	function country() {
		return $( '#edd_cc_address #billing_country' ).val() || '';
	}

	const b2b = window.vatrightEddB2b;

	function required( code ) {
		if ( ! b2b ) {
			return false;
		}
		switch ( b2b.mode ) {
			case 'everywhere':
				return true;
			case 'eu':
				return b2b.eu.indexOf( code ) !== -1;
			case 'countries':
				return b2b.countries.indexOf( code ) !== -1;
			default:
				return false;
		}
	}

	// Business-only mode refuses an empty field on the server; the label only follows the billing country.
	function markRequired() {
		const $label = $( 'label[for="vatright-edd-vat-number"]' );
		const $input = $( '#vatright-edd-vat-number' );
		if ( ! b2b || ! $label.length ) {
			return;
		}
		const isRequired = required( String( country() ).toUpperCase() );
		$label.empty().append( document.createTextNode( isRequired ? b2b.required : b2b.optional ) );
		if ( isRequired ) {
			$label.append( $( '<span class="edd-required-indicator" aria-hidden="true">*</span>' ), $( '<span class="screen-reader-text"></span>' ).text( b2b.required_text ) );
			$input.attr( 'aria-required', 'true' );
		} else {
			$input.removeAttr( 'aria-required' );
		}
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
		markRequired();
	} );

	markRequired();

	$( document.body ).on( 'edd_taxes_recalculated', function () {
		check( {}, false );
	} );

	// A number remembered from the customer's last order is only filled in; checking it is what applies it.
	const $prefilled = $( '#vatright-edd-vat[data-prefilled]' );
	if ( $prefilled.length ) {
		check( { vatright_edd_vat_number: $( '#vatright-edd-vat-number' ).val() }, true );
	}
} );
