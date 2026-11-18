( function ( wp, vocabulary ) {
	'use strict';
	const __ = wp.i18n.__;
	let sequence = 0;
	const normalize = value => value.normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' ).toLocaleLowerCase().trim();

	// One keyboard-accessible picker is shared by the Tools form and Gutenberg panel.
	window.EhrmanMetadataTermPicker = function ( host, settings ) {
		const topic = settings.kind === 'topic';
		const id = 'ehrman-term-picker-' + ++sequence;
		const options = ( vocabulary[ settings.kind ] || [] ).map( option => ( {
			label: option.label,
			search: [ option.label, ...( option.aliases || [] ) ].map( normalize ),
		} ) ).sort( ( a, b ) => a.label.localeCompare( b.label ) );
		const add = document.createElement( 'button' );
		add.type = 'button';
		add.className = 'button ehrman-metadata-picker__add';
		add.textContent = topic ? __( 'Add topic', 'ehrman-blog-discovery' ) : __( 'Add keyword', 'ehrman-blog-discovery' );
		const field = document.createElement( 'div' );
		field.hidden = true;
		const input = document.createElement( 'input' );
		input.type = 'text';
		input.autocomplete = 'off';
		input.setAttribute( 'role', 'combobox' );
		input.setAttribute( 'aria-autocomplete', 'list' );
		input.setAttribute( 'aria-expanded', 'false' );
		input.setAttribute( 'aria-controls', id );
		input.setAttribute( 'aria-label', topic ? __( 'Search existing topics', 'ehrman-blog-discovery' ) : __( 'Search existing secondary keywords', 'ehrman-blog-discovery' ) );
		const list = document.createElement( 'ul' );
		list.id = id;
		list.setAttribute( 'role', 'listbox' );
		list.setAttribute( 'aria-label', input.getAttribute( 'aria-label' ) );
		list.hidden = true;
		const status = document.createElement( 'div' );
		status.setAttribute( 'role', 'status' );
		field.append( input, list, status );
		host.classList.add( 'ehrman-metadata-picker' );
		host.append( add, field );
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

		function refresh() {
			const query = normalize( input.value );
			const selected = new Set( settings.selected().map( normalize ) );
			matches = field.hidden || input.disabled ? [] : options.filter( option => ! selected.has( normalize( option.label ) ) && ( ! query || option.search.some( text => text.includes( query ) ) ) );
			list.replaceChildren();
			matches.forEach( ( option, index ) => {
				const item = document.createElement( 'li' );
				item.id = id + '-' + index;
				item.setAttribute( 'role', 'option' );
				item.textContent = option.label;
				item.addEventListener( 'mousedown', event => event.preventDefault() );
				item.addEventListener( 'click', () => choose( index ) );
				list.append( item );
			} );
			list.scrollTop = 0;
			list.hidden = ! matches.length;
			input.setAttribute( 'aria-expanded', String( ! list.hidden ) );
			status.textContent = field.hidden || matches.length ? '' : query
				? ( topic ? __( 'No matching topics found', 'ehrman-blog-discovery' ) : __( 'No matching keywords found', 'ehrman-blog-discovery' ) )
				: ( topic ? __( 'No topics available to add', 'ehrman-blog-discovery' ) : __( 'No keywords available to add', 'ehrman-blog-discovery' ) );
			highlight( -1 );
		}

		function choose( index ) {
			if ( input.disabled || ! matches[ index ] ) return;
			settings.onSelect( matches[ index ].label );
			input.value = '';
			refresh();
			input.focus();
		}

		function close( focus ) {
			field.hidden = true;
			add.hidden = false;
			input.value = '';
			refresh();
			if ( focus ) add.focus();
		}
		function outside( event ) {
			if ( ! field.hidden && ! host.contains( event.target ) ) close( false );
		}
		add.addEventListener( 'click', () => { add.hidden = true; field.hidden = false; input.focus(); } );
		input.addEventListener( 'focus', refresh );
		input.addEventListener( 'input', refresh );
		input.addEventListener( 'keydown', event => {
			if ( event.key === 'Escape' ) { event.preventDefault(); event.stopPropagation(); close( true ); }
			if ( event.key === 'Enter' ) { event.preventDefault(); choose( active ); }
			if ( [ 'ArrowDown', 'ArrowUp' ].includes( event.key ) ) {
				event.preventDefault();
				if ( matches.length ) highlight( active < 0 ? ( event.key === 'ArrowDown' ? 0 : matches.length - 1 ) : ( active + ( event.key === 'ArrowDown' ? 1 : -1 ) + matches.length ) % matches.length );
			}
		} );
		document.addEventListener( 'pointerdown', outside );
		document.addEventListener( 'focusin', outside );
		return {
			refresh,
			setDisabled( disabled ) { add.disabled = disabled; input.disabled = disabled; if ( disabled ) close( false ); },
			destroy() { document.removeEventListener( 'pointerdown', outside ); document.removeEventListener( 'focusin', outside ); host.replaceChildren(); },
		};
	};
}( window.wp, window.EhrmanMetadataVocabulary || {} ) );
