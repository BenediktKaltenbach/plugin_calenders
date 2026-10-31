/**
 * Editor-Teil des Blocks „DTRV-Terminvorschlag“ (ohne Build-Schritt).
 * Keine Einstellungen; die Empfänger-Adresse steht unter Termine › Einstellungen.
 */
( function ( wp ) {
	const el = wp.element.createElement;
	const { useBlockProps } = wp.blockEditor;
	const ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'dtrv/vorschlag', {
		edit: function () {
			return el(
				'div',
				useBlockProps(),
				el(
					'div',
					{ style: { pointerEvents: 'none' } },
					el( ServerSideRender, { block: 'dtrv/vorschlag' } )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
