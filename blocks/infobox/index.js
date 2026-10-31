/**
 * Editor-Teil des Blocks „Termin-Infobox“ (ohne Build-Schritt).
 * In der Seitenleiste wird der verknüpfte Termin gewählt.
 */
( function ( wp ) {
	const el = wp.element.createElement;
	const { useState, useEffect } = wp.element;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, SelectControl, Spinner } = wp.components;
	const ServerSideRender = wp.serverSideRender;

	function TerminAuswahl( props ) {
		const [ termine, setTermine ] = useState( null );

		useEffect( function () {
			wp.apiFetch( { path: '/dtrv-termine/v1/termine' } )
				.then( setTermine )
				.catch( function () {
					setTermine( [] );
				} );
		}, [] );

		if ( termine === null ) {
			return el( Spinner );
		}
		const optionen = [ { value: 0, label: '– Termin wählen –' } ].concat(
			termine.map( function ( t ) {
				return { value: t.id, label: t.datum + ' – ' + t.titel + ' (' + t.kategorie + ')' };
			} )
		);
		return el( SelectControl, {
			label: 'Verknüpfter Termin',
			value: props.value,
			options: optionen,
			onChange: function ( v ) {
				props.onChange( parseInt( v, 10 ) || 0 );
			},
		} );
	}

	wp.blocks.registerBlockType( 'dtrv/infobox', {
		edit: function ( props ) {
			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: 'Termin' },
						el( TerminAuswahl, {
							value: props.attributes.terminId,
							onChange: function ( id ) {
								props.setAttributes( { terminId: id } );
							},
						} )
					)
				),
				el( ServerSideRender, { block: 'dtrv/infobox', attributes: props.attributes } )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
