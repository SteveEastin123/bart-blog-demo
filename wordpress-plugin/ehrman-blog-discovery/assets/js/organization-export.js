( () => {
	'use strict';
	const form = document.querySelector( '[data-ebd-export]' );
	if ( ! form ) return;
	const { __, sprintf } = wp.i18n;
	const areas = form.querySelector( '[data-ebd-export-areas]' );
	const status = form.querySelector( '[data-ebd-export-status]' );
	const buttons = Array.from( form.querySelectorAll( 'button[type="submit"]' ) );
	let busy = false;
	function refresh() {
		const selected = form.querySelector( '[name="scope"]:checked' ).value === 'selected';
		areas.hidden = ! selected;
		areas.disabled = ! selected || busy;
		const empty = ! areas.querySelector( 'input' ) || ( selected && ! areas.querySelector( 'input:checked' ) );
		buttons.forEach( button => { button.disabled = busy || empty || ( button.value === 'pdf' && ! window.pdfMake ); } );
		form.querySelectorAll( '[name="scope"]' ).forEach( radio => { radio.disabled = busy; } );
		form.setAttribute( 'aria-busy', String( busy ) );
	}
	form.addEventListener( 'change', () => { status.textContent = ''; refresh(); } );
	refresh();

	function documentDefinition( data ) {
		const content = [
			{ text: __( 'Browse Topics', 'ehrman-blog-discovery' ), fontSize: 23, bold: true, color: '#1d2327', margin: [ 0, 0, 0, 5 ] },
			{ text: __( 'Subject Areas, Categories, and Topics', 'ehrman-blog-discovery' ), fontSize: 12, color: '#50575e', margin: [ 0, 0, 0, 5 ] },
			{ text: data.date, fontSize: 9, color: '#50575e', margin: [ 0, 0, 0, 12 ] }
		];
		let subject = null;
		let category = null;
		let remaining = null;
		data.rows.forEach( row => {
			const newSubject = subject !== row.subject_area;
			const newCategory = newSubject || category !== row.category;
			if ( newCategory ) {
				// Keep each heading with its first child while allowing long lists to paginate.
				const heading = [];
				if ( newSubject ) heading.push( { text: row.subject_area, fontSize: 16, bold: true, color: '#174b64', margin: [ 0, 18, 0, 10 ] } );
				if ( row.category ) heading.push( { text: row.category, fontSize: 11, bold: true, margin: [ 12, newSubject ? 0 : 12, 0, 5 ] } );
				heading.push( row.topic
					? { ul: [ row.topic ], margin: [ 28, 0, 0, 0 ] }
					: { text: row.category ? __( 'No topics linked', 'ehrman-blog-discovery' ) : __( 'No categories linked', 'ehrman-blog-discovery' ), italics: true, color: '#50575e', margin: [ 28, 0, 0, 0 ] } );
				content.push( { stack: heading, unbreakable: true } );
				remaining = { ul: [], margin: [ 28, 0, 0, 0 ] };
				content.push( remaining );
			} else if ( row.topic ) {
				remaining.ul.push( row.topic );
			}
			subject = row.subject_area;
			category = row.category;
		} );
		return {
			info: { title: __( 'Browse Topics Structure', 'ehrman-blog-discovery' ), creator: 'Ehrman Blog Discovery' },
			pageSize: 'LETTER', pageMargins: [ 48, 42, 48, 44 ],
			defaultStyle: { font: 'Roboto', fontSize: 10, lineHeight: 1.2, color: '#1d2327' },
			content,
			footer: ( page, total ) => ( { text: sprintf( __( 'Page %1$d of %2$d', 'ehrman-blog-discovery' ), page, total ), alignment: 'right', fontSize: 9, color: '#50575e', margin: [ 48, 12, 48, 0 ] } )
		};
	}
	function download( blob, filename ) {
		const url = URL.createObjectURL( blob );
		const link = document.createElement( 'a' );
		link.href = url;
		link.download = filename;
		document.body.append( link );
		link.click();
		link.remove();
		setTimeout( () => URL.revokeObjectURL( url ), 60000 );
	}
	form.addEventListener( 'submit', async event => {
		event.preventDefault();
		if ( busy ) return;
		const format = event.submitter?.value || 'csv';
		const input = new FormData( form );
		input.set( 'format', format );
		busy = true;
		refresh();
		status.textContent = __( 'Preparing download...', 'ehrman-blog-discovery' );
		const controller = new AbortController();
		const timeout = setTimeout( () => controller.abort(), 60000 );
		try {
			// WordPress's hidden "action" field shadows the form.action property.
			const response = await fetch( form.getAttribute( 'action' ), { method: 'POST', body: input, credentials: 'same-origin', signal: controller.signal } );
			const type = response.headers.get( 'content-type' ) || '';
			if ( format === 'pdf' || ! response.ok ) {
				if ( ! type.includes( 'application/json' ) ) throw new Error( __( 'Export failed. Reload the page and try again.', 'ehrman-blog-discovery' ) );
				const result = await response.json();
				if ( ! response.ok || ! result.success ) throw new Error( result.data?.message || __( 'Export failed. Please try again.', 'ehrman-blog-discovery' ) );
				const blob = await window.pdfMake.createPdf( documentDefinition( result.data ) ).getBlob();
				download( blob, result.data.filename );
			} else {
				if ( ! type.includes( 'text/csv' ) ) throw new Error( __( 'Export failed. Reload the page and try again.', 'ehrman-blog-discovery' ) );
				const filename = /filename="([^"]+)"/.exec( response.headers.get( 'content-disposition' ) || '' )?.[ 1 ] || 'ehrman-browse-structure.csv';
				download( await response.blob(), filename );
			}
			status.textContent = __( 'Download ready.', 'ehrman-blog-discovery' );
		} catch ( error ) {
			status.textContent = error.name === 'AbortError' ? __( 'The export timed out. Please try again.', 'ehrman-blog-discovery' ) : error.message;
		} finally {
			clearTimeout( timeout );
			busy = false;
			refresh();
		}
	} );
} )();
