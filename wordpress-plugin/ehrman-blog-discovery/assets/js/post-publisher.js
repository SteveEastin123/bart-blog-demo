( function ( window, document ) {
	'use strict';
	const listeners = new Set();
	let baseline;
	let metadata = { dirty: false, busy: false };
	const value = selector => document.querySelector( selector )?.value || '';
	const contentEditor = () => window.tinymce?.get( 'content' );
	function content() {
		const editor = contentEditor();
		return editor && ! editor.isHidden() ? editor.getContent() : value( '#content' );
	}
	function source() {
		return [ value( '#title' ), content(), value( '#post_author_override' ),
			...Array.from( document.querySelectorAll( '#timestampdiv input, #timestampdiv select, #post_status, #post_name' ), item => item.value ) ];
	}
	function notify() { listeners.forEach( listener => listener() ); }
	function start() {
		// Keep the label with WordPress's tabs, including when the toolbar sticks on scroll.
		const heading = document.querySelector( '.ehrman-post-heading' );
		const tools = document.getElementById( 'wp-content-editor-tools' );
		if ( heading && tools ) tools.prepend( heading );
		baseline = source();
		const originalText = value( '#content' );
		function initialized() {
			// Normalize only the original content, never edits made before switching to Visual.
			if ( value( '#content' ) === originalText ) baseline[ 1 ] = content();
			notify();
		}
		const editor = contentEditor();
		if ( editor && ! editor.initialized ) editor.on( 'init', initialized );
		window.tinymce?.on( 'AddEditor', event => {
			if ( event.editor.id === 'content' ) event.editor.on( 'init', initialized );
		} );
		const form = document.querySelector( 'form#post' );
		if ( ! form ) return;
		form.addEventListener( 'input', notify );
		form.addEventListener( 'change', notify );
		form.addEventListener( 'submit', event => {
			if ( metadata.busy || ( metadata.dirty && ! window.confirm( window.wp.i18n.__( 'Discard unsaved metadata edits and save the post?', 'ehrman-blog-discovery' ) ) ) ) {
				event.preventDefault(); event.stopImmediatePropagation();
			}
		}, true );
		window.setInterval( notify, 500 );
	}
	window.EhrmanPostPublisher = {
		subscribe: listener => { listeners.add( listener ); return () => listeners.delete( listener ); },
		read: () => {
			const config = window.EhrmanPostIngestion;
			return { postId: config.form.postId, postStatus: config.form.postStatus,
				meta: { [ config.modeMeta ]: value( '[name="ehrman_metadata_mode"]:checked' ) || config.defaultMode },
				isDirty: !! baseline && JSON.stringify( baseline ) !== JSON.stringify( source() ), isSaving: false };
		},
		metadataState: state => { metadata = state; },
	};
	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', start );
	else start();
}( window, document ) );
