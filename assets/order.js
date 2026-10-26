jQuery( function ( $ ) {
	$( document.body ).on( 'click', '#vatright-edd-recheck', function () {
		const $row = $( this ).closest( '.vatright-edd-recheck' );
		const $result = $row.find( '.vatright-edd-recheck__result' );
		const $button = $( this ).prop( 'disabled', true );

		$.post( $row.data( 'ajax-url' ), {
			action: 'vatright_edd_recheck',
			nonce: $row.data( 'nonce' ),
			order_id: $row.data( 'order' ),
			vat_number: $row.find( '#vatright-edd-recheck-number' ).val(),
		} )
			.done( function ( response ) {
				$result.text( response.data.headline );
				window.location.reload();
			} )
			.fail( function ( xhr ) {
				const data = xhr.responseJSON && xhr.responseJSON.data;
				$result.text( data && data.message ? data.message : 'The check failed.' );
				$button.prop( 'disabled', false );
			} );
	} );
} );
