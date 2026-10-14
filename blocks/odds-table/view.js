/**
 * Visitor's odds format switch (Interactivity API).
 *
 * The server renders every format into each cell; CSS shows the one named in
 * the wrapper's data-format. This store only changes context.format (bound to
 * that attribute) and remembers the choice, so it survives page caching.
 */
import { getContext, getElement, store } from '@wordpress/interactivity';

const FORMATS = [ 'decimal', 'fractional', 'american' ];
const STORAGE_KEY = 'bestOddsTableFormat';

// Storage can be unavailable (privacy mode, blocked cookies); the switch
// still works for the current page then.
function remember( format ) {
	try {
		window.localStorage.setItem( STORAGE_KEY, format );
	} catch {
		// Not remembered; nothing else to do.
	}
}

function remembered() {
	try {
		const format = window.localStorage.getItem( STORAGE_KEY );
		return FORMATS.includes( format ) ? format : null;
	} catch {
		return null;
	}
}

store( 'best-odds-table', {
	actions: {
		setFormat( event ) {
			const format = event.target.value;
			if ( FORMATS.includes( format ) ) {
				getContext().format = format;
				remember( format );
			}
		},
	},
	callbacks: {
		restoreFormat() {
			const format = remembered();
			if ( format ) {
				getContext().format = format;
			}
		},
		// A bad logo address must not show a broken-image icon. The image may
		// have failed before this script ran, so check its state as well as
		// listening for a later error.
		hideBrokenLogo() {
			const { ref } = getElement();
			const hide = () => ref.classList.add( 'is-broken' );

			if ( ref.complete && ref.naturalWidth === 0 ) {
				hide();
			} else {
				ref.addEventListener( 'error', hide, { once: true } );
			}
		},
	},
} );
