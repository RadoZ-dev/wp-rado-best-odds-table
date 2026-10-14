/**
 * Editor UI for the odds table block.
 *
 * The preview is the real server render (ServerSideRender runs render.php),
 * so the editor and the published page can never show different tables.
 */
import { __, sprintf } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	Button,
	ComboboxControl,
	Disabled,
	Notice,
	PanelBody,
	Placeholder,
	SelectControl,
	Spinner,
	ToggleControl,
} from '@wordpress/components';
import { dateI18n } from '@wordpress/date';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import ServerSideRender from '@wordpress/server-side-render';

import metadata from './block.json';

const API = '/best-odds-table/v1';

const FORMAT_OPTIONS = [
	{ value: 'decimal', label: __( 'Decimal (2.50)', 'best-odds-table' ) },
	{ value: 'fractional', label: __( 'Fractional (6/4)', 'best-odds-table' ) },
	{ value: 'american', label: __( 'American (+150)', 'best-odds-table' ) },
];

/**
 * GET a plugin endpoint once per path.
 *
 * @param {string} path REST path.
 * @return {{data: *, error: *}} Response data, or the error.
 */
function useApi( path ) {
	const [ result, setResult ] = useState( { data: null, error: null } );

	useEffect( () => {
		let active = true;
		apiFetch( { path } ).then(
			( data ) => active && setResult( { data, error: null } ),
			( error ) => active && setResult( { data: null, error } )
		);

		return () => {
			active = false;
		};
	}, [ path ] );

	return result;
}

/**
 * Event and market selects. Used in the placeholder and in the sidebar.
 *
 * @param {Object}   props
 * @param {Array}    props.events    Events from GET /events (enabled markets only).
 * @param {string}   props.eventId
 * @param {string}   props.marketKey
 * @param {Function} props.onChange  Receives { eventId, marketKey }.
 */
function EventPicker( { events, eventId, marketKey, onChange } ) {
	const event = events.find( ( item ) => item.id === eventId );
	const markets = Object.entries( event?.markets ?? {} );

	const selectEvent = ( id ) => {
		const next = events.find( ( item ) => item.id === id );
		const keys = Object.keys( next?.markets ?? {} );
		// Keep the market when the new event offers it, otherwise take its first one.
		onChange( {
			eventId: id ?? '',
			marketKey: keys.includes( marketKey )
				? marketKey
				: ( keys[ 0 ] ?? '' ),
		} );
	};

	return (
		<>
			<ComboboxControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Event', 'best-odds-table' ) }
				value={ eventId || null }
				options={ events.map( ( item ) => ( {
					value: item.id,
					label: sprintf(
						/* translators: 1: "Home v Away", 2: competition, 3: kick-off date and time. */
						__( '%1$s (%2$s, %3$s)', 'best-odds-table' ),
						item.name,
						item.competition,
						dateI18n( 'j M, H:i', item.starts_at )
					),
				} ) ) }
				onChange={ selectEvent }
			/>
			{ event && (
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Market', 'best-odds-table' ) }
					value={ marketKey }
					options={ markets.map( ( [ key, label ] ) => ( {
						value: key,
						label,
					} ) ) }
					onChange={ ( key ) =>
						onChange( { eventId, marketKey: key } )
					}
				/>
			) }
		</>
	);
}

/**
 * Choose and order bookmakers. An empty selection means "all enabled",
 * so bookmakers enabled later in settings appear without editing posts.
 *
 * @param {Object}   props
 * @param {Array}    props.available Enabled bookmakers from GET /bookmakers, in settings order.
 * @param {string[]} props.selected  Saved ids, in display order.
 * @param {Function} props.onChange  Receives the new id list.
 */
function BookmakersPanel( { available, selected, onChange } ) {
	const byId = Object.fromEntries( available.map( ( b ) => [ b.id, b ] ) );
	// Ids of bookmakers disabled since the post was saved are not shown.
	const chosen = selected.filter( ( id ) => byId[ id ] );
	const rest = available.filter( ( b ) => ! chosen.includes( b.id ) );

	const move = ( index, offset ) => {
		const next = [ ...chosen ];
		[ next[ index ], next[ index + offset ] ] = [
			next[ index + offset ],
			next[ index ],
		];
		onChange( next );
	};

	return (
		<PanelBody title={ __( 'Bookmakers', 'best-odds-table' ) }>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Show all enabled bookmakers', 'best-odds-table' ) }
				help={ __(
					'Follows the order in Settings > Best Odds Table, including bookmakers enabled later.',
					'best-odds-table'
				) }
				checked={ chosen.length === 0 }
				onChange={ ( all ) =>
					onChange( all ? [] : available.map( ( b ) => b.id ) )
				}
			/>

			{ chosen.length > 0 && (
				<>
					<ul className="bot-editor-list">
						{ chosen.map( ( id, index ) => (
							<li key={ id }>
								<span>{ byId[ id ].name }</span>
								<Button
									size="small"
									icon="arrow-up-alt2"
									label={ sprintf(
										/* translators: %s: bookmaker name. */
										__( 'Move %s up', 'best-odds-table' ),
										byId[ id ].name
									) }
									disabled={ index === 0 }
									onClick={ () => move( index, -1 ) }
								/>
								<Button
									size="small"
									icon="arrow-down-alt2"
									label={ sprintf(
										/* translators: %s: bookmaker name. */
										__( 'Move %s down', 'best-odds-table' ),
										byId[ id ].name
									) }
									disabled={ index === chosen.length - 1 }
									onClick={ () => move( index, 1 ) }
								/>
								<Button
									size="small"
									icon="no-alt"
									label={ sprintf(
										/* translators: %s: bookmaker name. */
										__( 'Remove %s', 'best-odds-table' ),
										byId[ id ].name
									) }
									// Removing the last one would silently mean "all".
									disabled={ chosen.length === 1 }
									onClick={ () =>
										onChange(
											chosen.filter(
												( other ) => other !== id
											)
										)
									}
								/>
							</li>
						) ) }
					</ul>

					{ rest.length > 0 && (
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Add bookmaker', 'best-odds-table' ) }
							value=""
							options={ [
								{
									value: '',
									label: __( 'Choose…', 'best-odds-table' ),
								},
								...rest.map( ( b ) => ( {
									value: b.id,
									label: b.name,
								} ) ),
							] }
							onChange={ ( id ) =>
								id && onChange( [ ...chosen, id ] )
							}
						/>
					) }
				</>
			) }
		</PanelBody>
	);
}

/**
 * Server-rendered preview. Requests GET /odds first: for an editor that
 * fetches a pair nobody watches yet (only if the source lists it), so the
 * preview shows real odds instead of "not available".
 *
 * @param {Object} props
 * @param {Object} props.attributes Block attributes.
 */
function Preview( { attributes } ) {
	const { eventId, marketKey } = attributes;
	const [ ready, setReady ] = useState( false );

	useEffect( () => {
		let active = true;
		setReady( false );
		apiFetch( {
			path: addQueryArgs( `${ API }/odds`, {
				event: eventId,
				market: marketKey,
			} ),
		} )
			// A 404 means no odds yet; the server render shows that message.
			.catch( () => {} )
			.finally( () => active && setReady( true ) );

		return () => {
			active = false;
		};
	}, [ eventId, marketKey ] );

	if ( ! ready ) {
		return <Spinner />;
	}

	return (
		<Disabled>
			<ServerSideRender
				block={ metadata.name }
				attributes={ attributes }
			/>
		</Disabled>
	);
}

export default function Edit( { attributes, setAttributes } ) {
	const { eventId, marketKey, bookmakers, format } = attributes;
	const blockProps = useBlockProps();
	const events = useApi( `${ API }/events` );
	const available = useApi( `${ API }/bookmakers` );
	const configured = eventId !== '' && marketKey !== '';

	const error = events.error || available.error;
	const loading = ! error && ( ! events.data || ! available.data );

	const picker = events.data && (
		<EventPicker
			events={ events.data }
			eventId={ eventId }
			marketKey={ marketKey }
			onChange={ setAttributes }
		/>
	);

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Odds', 'best-odds-table' ) }>
					{ picker }
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Default format', 'best-odds-table' ) }
						help={ __(
							'Visitors can switch format on the page.',
							'best-odds-table'
						) }
						value={ format }
						options={ FORMAT_OPTIONS }
						onChange={ ( value ) =>
							setAttributes( { format: value } )
						}
					/>
				</PanelBody>
				{ available.data && (
					<BookmakersPanel
						available={ available.data }
						selected={ bookmakers }
						onChange={ ( ids ) =>
							setAttributes( { bookmakers: ids } )
						}
					/>
				) }
			</InspectorControls>

			<div { ...blockProps }>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error.message ||
							__(
								'Could not load odds data.',
								'best-odds-table'
							) }
					</Notice>
				) }
				{ loading && <Spinner /> }
				{ ! loading && ! error && ! configured && (
					<Placeholder
						icon="editor-table"
						label={ __( 'Best Odds Table', 'best-odds-table' ) }
						instructions={
							events.data.length > 0
								? __(
										'Choose an event and a market.',
										'best-odds-table'
									)
								: __(
										'No events are available yet. They appear after the next index refresh.',
										'best-odds-table'
									)
						}
					>
						{ picker }
					</Placeholder>
				) }
				{ ! loading && ! error && configured && (
					<Preview attributes={ attributes } />
				) }
			</div>
		</>
	);
}
