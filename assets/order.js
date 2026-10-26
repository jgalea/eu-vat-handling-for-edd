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

jQuery( function ( $ ) {
	$( document.body ).on( 'click', '#vatright-edd-edit-save', function () {
		const $box = $( this ).closest( '.vatright-edd-edit' );
		const $result = $box.find( '.vatright-edd-edit__result' );
		const $button = $( this ).prop( 'disabled', true );

		$.post( $box.data( 'ajax-url' ), {
			action: 'vatright_edd_edit',
			nonce: $box.data( 'nonce' ),
			order_id: $box.data( 'order' ),
			vat_number: $box.find( '#vatright-edd-edit-number' ).val(),
			country: $box.find( '#vatright-edd-edit-country' ).val(),
			treatment: $box.find( '#vatright-edd-edit-treatment' ).val(),
			reason: $box.find( '#vatright-edd-edit-reason' ).val(),
		} )
			.done( function () {
				const url = new URL( window.location.href );
				url.searchParams.set( 'vatright_edited', '1' );
				window.location.assign( url.toString() );
			} )
			.fail( function ( xhr ) {
				const data = xhr.responseJSON && xhr.responseJSON.data;
				$result.text( data && data.message ? data.message : 'The correction failed.' );
				$button.prop( 'disabled', false );
			} );
	} );
} );
