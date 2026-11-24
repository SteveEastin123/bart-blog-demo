( function () {
	'use strict';
	const { sprintf } = wp.i18n;
	const search = document.querySelector( '[data-ebd-topic-search]' );
	if ( search ) {
		const input = search.querySelector( '[name="s"]' );
		const results = search.querySelector( '[data-ebd-topic-results]' );
		const list = search.querySelector( '#ebd-topic-matches' );
		const status = search.querySelector( '[data-ebd-topic-status]' );
		const more = search.querySelector( '[data-ebd-topic-more]' );
		const topics = Array.from( search.querySelector( '[data-ebd-topic-directory]' ).content.children );
		const close = () => { results.hidden = true; };
		const refresh = () => {
			const query = input.value.trim().toLocaleLowerCase();
			list.replaceChildren();
			if ( ! query ) { status.textContent = ''; close(); return; }
			const matches = topics.filter( topic => topic.querySelector( '[data-ebd-topic-name]' ).textContent.toLocaleLowerCase().includes( query ) );
			matches.slice( 0, 12 ).forEach( topic => list.append( topic.cloneNode( true ) ) );
			results.hidden = false;
			status.textContent = matches.length ? sprintf( search.dataset.matchCount, matches.length ) : search.dataset.empty;
			more.hidden = matches.length <= 12;
			const url = new URL( search.action );
			url.searchParams.set( 'page', search.querySelector( '[name="page"]' ).value );
			const tab = search.querySelector( '[name="tab"]' );
			if ( tab ) url.searchParams.set( 'tab', tab.value );
			url.searchParams.set( 's', input.value.trim() );
			more.href = url.href;
		};
		input.addEventListener( 'input', refresh );
		input.addEventListener( 'focus', refresh );
		input.addEventListener( 'keydown', event => {
			if ( event.key === 'Escape' ) { event.preventDefault(); close(); }
			if ( [ 'ArrowDown', 'ArrowUp' ].includes( event.key ) ) {
				event.preventDefault();
				refresh();
				const links = Array.from( list.querySelectorAll( 'a' ) );
				if ( links.length ) links[ event.key === 'ArrowDown' ? 0 : links.length - 1 ].focus();
			}
		} );
		list.addEventListener( 'keydown', event => {
			if ( event.key === 'Escape' ) { event.preventDefault(); input.focus(); close(); }
			if ( [ 'ArrowDown', 'ArrowUp' ].includes( event.key ) ) {
				event.preventDefault();
				const links = Array.from( list.querySelectorAll( 'a' ) );
				const next = links.indexOf( document.activeElement ) + ( event.key === 'ArrowDown' ? 1 : -1 );
				if ( next >= 0 && next < links.length ) links[ next ].focus();
				else input.focus();
			}
		} );
		document.addEventListener( 'pointerdown', event => { if ( ! search.contains( event.target ) ) close(); } );
		document.addEventListener( 'focusin', event => { if ( ! search.contains( event.target ) ) close(); } );
	}
	const form = document.querySelector( '[data-ebd-topic-form]' );
	if ( ! form ) return;
	const save = form.querySelector( '[type="submit"]' );
	const groups = Array.from( form.querySelectorAll( '[data-ebd-link-group]' ) );
	const requiredGroups = groups.filter( group => group.querySelector( 'select' ).required );
	const emptyGroup = () => requiredGroups.find( group => ! group.querySelector( 'select' ).selectedOptions.length );
	let dirty = false;
	groups.forEach( group => {
		const select = group.querySelector( 'select' );
		const required = select.required;
		const host = group.querySelector( '[data-ebd-category-picker]' );
		const warning = group.querySelector( '[data-ebd-link-warning]' );
		const labels = group.dataset;
		const options = Array.from( select.options );
		const normalize = value => value.normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' ).toLocaleLowerCase().trim();
		const chips = document.createElement( 'ul' );
		chips.className = 'ehrman-organization__chips';
		chips.setAttribute( 'aria-label', labels.selected );
		const add = document.createElement( 'button' );
		add.type = 'button';
		add.className = 'button';
		add.textContent = labels.add;
		const field = document.createElement( 'div' );
		field.hidden = true;
		const input = document.createElement( 'input' );
		input.type = 'text';
		input.autocomplete = 'off';
		input.setAttribute( 'role', 'combobox' );
		input.setAttribute( 'aria-label', labels.search );
		input.setAttribute( 'aria-autocomplete', 'list' );
		input.setAttribute( 'aria-controls', select.id + '-options' );
		input.setAttribute( 'aria-expanded', 'false' );
		const list = document.createElement( 'ul' );
		list.id = select.id + '-options';
		list.className = 'ehrman-organization__options';
		list.setAttribute( 'role', 'listbox' );
		list.setAttribute( 'aria-label', input.getAttribute( 'aria-label' ) );
		const status = document.createElement( 'div' );
		status.setAttribute( 'role', 'status' );
		field.append( input, list, status );
		host.append( chips, add, field );
		// Keep the native control as the POST source and as the no-JavaScript fallback.
		select.hidden = true;
		select.required = false;
		let matches = [];
		let active = -1;
		function highlight( index ) {
			active = index;
			Array.from( list.children ).forEach( ( item, i ) => item.setAttribute( 'aria-selected', String( i === active ) ) );
			if ( active < 0 ) input.removeAttribute( 'aria-activedescendant' );
			else {
				input.setAttribute( 'aria-activedescendant', list.children[ active ].id );
				list.children[ active ].scrollIntoView( { block: 'nearest' } );
			}
		}
		function refreshOptions() {
			const query = normalize( input.value );
			matches = field.hidden ? [] : options.filter( option => ! option.selected && normalize( option.textContent ).includes( query ) );
			list.replaceChildren();
			matches.forEach( ( option, index ) => {
				const item = document.createElement( 'li' );
				item.id = select.id + '-option-' + index;
				item.setAttribute( 'role', 'option' );
				item.textContent = option.textContent;
				item.addEventListener( 'mousedown', event => event.preventDefault() );
				item.addEventListener( 'click', () => choose( index ) );
				list.append( item );
			} );
			list.hidden = ! matches.length;
			list.scrollTop = 0;
			input.setAttribute( 'aria-expanded', String( ! list.hidden ) );
			status.textContent = field.hidden || matches.length ? '' : labels.empty;
			highlight( -1 );
		}
		function refreshSelected() {
			chips.replaceChildren();
			options.filter( option => option.selected ).forEach( option => {
				const chip = document.createElement( 'li' );
				const label = document.createElement( 'span' );
				label.textContent = option.textContent;
				const remove = document.createElement( 'button' );
				remove.type = 'button';
				remove.setAttribute( 'aria-label', sprintf( labels.remove, option.textContent ) );
				remove.title = remove.getAttribute( 'aria-label' );
				const icon = document.createElement( 'span' );
				icon.className = 'dashicons dashicons-no-alt';
				icon.setAttribute( 'aria-hidden', 'true' );
				remove.append( icon );
				remove.addEventListener( 'click', () => { option.selected = false; dirty = true; refreshSelected(); close(); add.focus(); } );
				chip.append( label, remove );
				chips.append( chip );
			} );
			const empty = ! select.selectedOptions.length;
			warning.hidden = ! required || ! empty;
			save.disabled = Boolean( emptyGroup() );
		}
		function close() { field.hidden = true; add.hidden = false; input.value = ''; refreshOptions(); }
		function choose( index ) {
			if ( ! matches[ index ] ) return;
			matches[ index ].selected = true;
			dirty = true;
			refreshSelected();
			close();
			add.focus();
		}
		add.addEventListener( 'click', () => { add.hidden = true; field.hidden = false; refreshOptions(); input.focus(); } );
		input.addEventListener( 'input', refreshOptions );
		input.addEventListener( 'keydown', event => {
			if ( event.key === 'Escape' ) { event.preventDefault(); close(); add.focus(); }
			if ( event.key === 'Enter' ) { event.preventDefault(); choose( active ); }
			if ( [ 'ArrowDown', 'ArrowUp' ].includes( event.key ) ) {
				event.preventDefault();
				if ( matches.length ) highlight( active < 0 ? ( event.key === 'ArrowDown' ? 0 : matches.length - 1 ) : ( active + ( event.key === 'ArrowDown' ? 1 : -1 ) + matches.length ) % matches.length );
			}
		} );
		document.addEventListener( 'pointerdown', event => { if ( ! host.contains( event.target ) ) close(); } );
		document.addEventListener( 'focusin', event => { if ( ! host.contains( event.target ) ) close(); } );
		refreshSelected();
	} );
	form.addEventListener( 'input', () => { dirty = true; } );
	form.addEventListener( 'submit', event => {
		const empty = emptyGroup();
		if ( empty ) {
			event.preventDefault();
			empty.querySelector( '[data-ebd-link-warning]' ).hidden = false;
			empty.querySelector( 'button' ).focus();
		}
		else dirty = false;
	} );
	window.addEventListener( 'beforeunload', event => { if ( dirty ) { event.preventDefault(); event.returnValue = ''; } } );
}() );
