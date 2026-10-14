/**
 * Drag-to-reorder for the bookmakers table.
 *
 * No hidden "order" fields are needed: the browser submits inputs in DOM
 * order, and PHP keeps that order in the parsed array.
 */
( function ( $ ) {
	$( function () {
		$( '.bot-bookmakers tbody' ).sortable( {
			handle: '.bot-col-handle',
			axis: 'y',
			placeholder: 'bot-sortable-placeholder',
			forcePlaceholderSize: true,
		} );
	} );
} )( jQuery );
