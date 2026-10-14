/**
 * Registers the odds table block in the editor.
 *
 * Dynamic block: save() returns null, so only the attributes are stored in
 * post content and render.php builds the markup on every request. Changing
 * the markup later never causes "invalid block" errors.
 */
import { registerBlockType } from '@wordpress/blocks';

import metadata from './block.json';
import Edit from './edit';
import './style.scss';
import './editor.scss';

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );
