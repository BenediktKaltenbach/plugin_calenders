/**
 * Editor-Teil des Blocks „DTRV-Termine“ (ohne Build-Schritt).
 * Die Vorschau rendert der Server, damit Editor und Seite gleich aussehen.
 */
( function ( wp ) {
	const el = wp.element.createElement;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, CheckboxControl, RangeControl, ToggleControl } = wp.components;
	const ServerSideRender = wp.serverSideRender;

	const KATEGORIEN = [
		[ 'wettkampf', 'Wettkampf' ],
		[ 'breitensport', 'Breitensport' ],
		[ 'ausbildung', 'Ausbildung' ],
		[ 'verband', 'Verband' ],
	];

	wp.blocks.registerBlockType( 'dtrv/termine', {
		edit: function ( props ) {
			const a = props.attributes;
			const set = props.setAttributes;

			const umschalten = function ( slug, an ) {
				const liste = a.kategorien.filter( function ( k ) {
					return k !== slug;
				} );
				if ( an ) {
					liste.push( slug );
				}
				set( { kategorien: liste } );
			};

			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: 'Kategorien' },
						el( 'p', null, 'Keine Auswahl = alle Kategorien.' ),
						KATEGORIEN.map( function ( k ) {
							return el( CheckboxControl, {
								key: k[ 0 ],
								label: k[ 1 ],
								checked: a.kategorien.indexOf( k[ 0 ] ) !== -1,
								onChange: function ( an ) {
									umschalten( k[ 0 ], an );
								},
							} );
						} )
					),
					el(
						PanelBody,
						{ title: 'Auswahl' },
						el( RangeControl, {
							label: 'Anzahl (0 = alle)',
							value: a.anzahl,
							min: 0,
							max: 50,
							onChange: function ( v ) {
								set( { anzahl: v || 0 } );
							},
						} ),
						el( RangeControl, {
							label: 'Zeitraum in Monaten (0 = unbegrenzt)',
							value: a.monate,
							min: 0,
							max: 36,
							onChange: function ( v ) {
								set( { monate: v || 0 } );
							},
						} ),
						el( ToggleControl, {
							label: 'Vergangene Termine zeigen',
							checked: a.vergangene,
							onChange: function ( v ) {
								set( { vergangene: v } );
							},
						} )
					)
				),
				el( ServerSideRender, { block: 'dtrv/termine', attributes: a } )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
