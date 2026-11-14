( function () {
	'use strict';
	const form = document.querySelector( '[data-ebd-metadata-review]' );
	if ( ! form ) return;
	const warning = form.querySelector( '[data-ebd-topic-warning]' );
	const actions = Array.from( form.querySelectorAll( '[name="ingestion_intent"]' ), button => ( { button, disabled: button.disabled } ) );
	function validateTopics() {
		const empty = form.dataset.requiresTopics === '1' && ! form.querySelector( '[data-ebd-selections="topic"] [data-ebd-selected] li' );
		warning.hidden = ! empty;
		actions.forEach( action => { action.button.disabled = action.disabled || empty; } );
		return ! empty;
	}
	form.querySelectorAll( '[data-ebd-term-toggle]' ).forEach( button => {
		button.hidden = false;
		button.addEventListener( 'click', function () {
			const term = button.closest( 'li' );
			const group = button.closest( '[data-ebd-selections]' );
			const selected = group.querySelector( '[data-ebd-selected]' );
			const removed = group.querySelector( '[data-ebd-removed]' );
			const removing = ! term.classList.contains( 'is-removed' );
			const destination = removing ? removed.querySelector( 'ul' ) : selected;
			const next = Array.from( destination.children ).find( item => Number( item.dataset.ebdOrder ) > Number( term.dataset.ebdOrder ) );
			destination.insertBefore( term, next || null );
			term.classList.toggle( 'is-removed', removing );
			term.querySelector( 'input' ).disabled = ! removing;
			button.setAttribute( 'aria-label', removing ? button.dataset.restoreLabel : button.dataset.removeLabel );
			button.title = button.getAttribute( 'aria-label' );
			button.querySelector( '.dashicons' ).className = 'dashicons dashicons-' + ( removing ? 'undo' : 'no-alt' );
			removed.hidden = ! removed.querySelector( 'li' );
			validateTopics();
			button.focus();
		} );
	} );
	form.addEventListener( 'submit', event => { if ( ! validateTopics() ) event.preventDefault(); } );
	validateTopics();
}() );
