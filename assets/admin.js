/**
 * Settings page behaviour.
 *
 * Drag-to-reorder for the bookmakers table: no hidden "order" fields are
 * needed, because the browser submits inputs in DOM order and PHP keeps that
 * order in the parsed array.
 *
 * Logo fields: "Upload logo" opens the Media Library (upload or pick), fills
 * the hidden address field and shows a preview; "Remove" clears it.
 *
 * @param {jQuery} $ WordPress's jQuery, passed in because it runs in noConflict mode.
 */
/* global jQuery */
( function ( $ ) {
	/**
	 * Show the field's state: preview, button label and "Remove" link.
	 *
	 * @param {jQuery} $field The .bot-logo-field wrapper.
	 * @param {string} url    Logo address, or '' for none.
	 */
	function setLogo( $field, url ) {
		const $choose = $field.find( '.bot-logo-choose' );

		$field.find( '.bot-logo-url' ).val( url );
		$field.find( '.bot-logo-remove' ).prop( 'hidden', ! url );
		$choose.text(
			url ? $choose.data( 'label-change' ) : $choose.data( 'label-add' )
		);

		const $preview = $field.find( '.bot-logo-preview' );
		if ( url ) {
			$preview.attr( 'src', url ).prop( 'hidden', false );
		} else {
			$preview.removeAttr( 'src' ).prop( 'hidden', true );
		}
	}

	$( function () {
		$( '.bot-bookmakers tbody' ).sortable( {
			handle: '.bot-col-handle',
			axis: 'y',
			placeholder: 'bot-sortable-placeholder',
			forcePlaceholderSize: true,
		} );

		$( document ).on( 'click', '.bot-logo-choose', function ( event ) {
			event.preventDefault();

			const $button = $( this );
			const $field = $button.closest( '.bot-logo-field' );
			const frame = wp.media( {
				title: $button.data( 'title' ),
				button: { text: $button.data( 'button' ) },
				library: { type: 'image' },
				multiple: false,
			} );

			frame.on( 'select', function () {
				const url = frame
					.state()
					.get( 'selection' )
					.first()
					.get( 'url' );
				setLogo( $field, url );
			} );

			frame.open();
		} );

		$( document ).on( 'click', '.bot-logo-remove', function ( event ) {
			event.preventDefault();
			setLogo( $( this ).closest( '.bot-logo-field' ), '' );
		} );
	} );
} )( jQuery );
