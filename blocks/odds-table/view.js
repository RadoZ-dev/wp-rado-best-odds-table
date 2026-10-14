/**
 * Visitor's odds format switch (Interactivity API).
 *
 * The server renders every format into each cell; CSS shows the one named in
 * the wrapper's data-format, which is bound to that table's context.format.
 *
 * Each table starts with the editor's default. Once the visitor picks a
 * format, it is a reader preference, like a language: it goes into the shared
 * state.chosen, every table on the page follows it at once, and it is
 * remembered for later pages (in the browser, so it survives page caching).
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

const { state } = store( 'best-odds-table', {
	state: {
		// Shared by every table on the page; null until the visitor picks one.
		chosen: remembered(),
	},
	actions: {
		setFormat( event ) {
			const format = event.target.value;
			if ( FORMATS.includes( format ) ) {
				state.chosen = format;
				remember( format );
			}
		},
	},
	callbacks: {
		// Runs for each table, and again whenever state.chosen changes: follow
		// the visitor's choice once there is one, otherwise keep this table's
		// own default from the editor.
		applyChosenFormat() {
			if ( state.chosen ) {
				getContext().format = state.chosen;
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
