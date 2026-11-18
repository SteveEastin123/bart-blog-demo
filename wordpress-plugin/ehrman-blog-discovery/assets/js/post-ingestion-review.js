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
	function bindToggle( button ) {
		button.hidden = false;
		button.addEventListener( 'click', () => toggleTerm( button, true ) );
	}
	function toggleTerm( button, focus ) {
		const term = button.closest( 'li' );
		const group = button.closest( '[data-ebd-selections]' );
		const selected = group.querySelector( '[data-ebd-selected]' );
		const removed = group.querySelector( '[data-ebd-removed]' );
		const removing = ! term.classList.contains( 'is-removed' );
		const destination = removing ? removed.querySelector( 'ul' ) : selected;
		const next = Array.from( destination.children ).find( item => Number( item.dataset.ebdOrder ) > Number( term.dataset.ebdOrder ) );
		destination.insertBefore( term, next || null );
		term.classList.toggle( 'is-removed', removing );
		term.querySelector( 'input' ).disabled = term.dataset.ebdAdded ? removing : ! removing;
		button.setAttribute( 'aria-label', removing ? button.dataset.restoreLabel : button.dataset.removeLabel );
		button.title = button.getAttribute( 'aria-label' );
		button.querySelector( '.dashicons' ).className = 'dashicons dashicons-' + ( removing ? 'undo' : 'no-alt' );
		removed.hidden = ! removed.querySelector( 'li' );
		validateTopics();
		group.termPicker.refresh();
		if ( focus ) button.focus();
	}
	form.querySelectorAll( '[data-ebd-selections]' ).forEach( group => {
		const kind = group.dataset.ebdSelections;
		const selected = group.querySelector( '[data-ebd-selected]' );
		group.querySelectorAll( '[data-ebd-term-toggle]' ).forEach( bindToggle );
		group.termPicker = window.EhrmanMetadataTermPicker( group.querySelector( '[data-ebd-term-picker]' ), {
			kind,
			selected: () => Array.from( form.querySelectorAll( '[data-ebd-selected] input' ), input => input.value ),
			onSelect: label => {
				const existing = Array.from( group.querySelectorAll( 'li' ) ).find( item => item.querySelector( 'input' )?.value === label );
				if ( existing ) {
					if ( existing.classList.contains( 'is-removed' ) ) toggleTerm( existing.querySelector( 'button' ), false );
					return;
				}
				const term = document.createElement( 'li' );
				term.className = 'ehrman-metadata-selections__term';
				term.dataset.ebdOrder = String( group.querySelectorAll( 'li.ehrman-metadata-selections__term' ).length );
				term.dataset.ebdAdded = '1';
				const text = document.createElement( 'span' );
				text.className = 'ehrman-metadata-selections__label';
				text.textContent = label;
				const input = document.createElement( 'input' );
				input.type = 'hidden';
				input.name = kind === 'topic' ? 'added_topics[]' : 'added_keywords[]';
				input.value = label;
				const button = document.createElement( 'button' );
				button.type = 'button';
				button.className = 'ehrman-metadata-selections__button';
				button.dataset.ebdTermToggle = '';
				button.dataset.removeLabel = wp.i18n.sprintf( kind === 'topic' ? wp.i18n.__( 'Remove topic: %s', 'ehrman-blog-discovery' ) : wp.i18n.__( 'Remove keyword: %s', 'ehrman-blog-discovery' ), label );
				button.dataset.restoreLabel = wp.i18n.sprintf( kind === 'topic' ? wp.i18n.__( 'Restore topic: %s', 'ehrman-blog-discovery' ) : wp.i18n.__( 'Restore keyword: %s', 'ehrman-blog-discovery' ), label );
				button.setAttribute( 'aria-label', button.dataset.removeLabel );
				button.title = button.dataset.removeLabel;
				const icon = document.createElement( 'span' );
				icon.className = 'dashicons dashicons-no-alt';
				icon.setAttribute( 'aria-hidden', 'true' );
				button.append( icon );
				term.append( text, input, button );
				bindToggle( button );
				selected.append( term );
				validateTopics();
			},
		} );
	} );
	form.addEventListener( 'submit', event => { if ( ! validateTopics() ) event.preventDefault(); } );
	validateTopics();
}() );
