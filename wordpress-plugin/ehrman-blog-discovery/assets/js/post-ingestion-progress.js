( function () {
	'use strict';

	const panel = document.querySelector( '[data-ebd-metadata-progress]' );
	const config = window.EhrmanMetadataProgress;
	if ( ! panel || ! config ) {
		return;
	}
	const __ = window.wp.i18n.__;
	const status = panel.querySelector( '[data-ebd-generation-status]' );
	const detail = panel.querySelector( '[data-ebd-generation-detail]' );
	const spinner = panel.querySelector( '.spinner' );
	const errorNotice = panel.querySelector( '[data-ebd-generation-error]' );
	const waitDeadline = Date.now() + 10 * 60 * 1000;
	let waiting = true;

	window.addEventListener( 'beforeunload', function ( event ) {
		if ( waiting ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );

	function request( method, suffix = '' ) {
		return window.wp.apiFetch( {
			path: config.restPath + suffix,
			method: method,
			headers: { 'X-WP-Nonce': config.nonce },
		} );
	}

	async function openRecord( result ) {
		let draft = result && result.draft;
		const id = Number( draft && draft.id );
		if ( ! Number.isSafeInteger( id ) || id <= 0 ) {
			return false;
		}
		// A saved record may still be running after a timeout or a request from another tab.
		while ( draft.analysisActive && ! draft.analysisStalled ) {
			if ( Date.now() >= waitDeadline ) {
				throw new Error( __( 'Metadata generation has not finished. Check this post in Search Metadata for its current status.', 'ehrman-blog-discovery' ) );
			}
			detail.textContent = __( 'Metadata is still being generated. This page will open the review automatically when it is ready.', 'ehrman-blog-discovery' );
			await new Promise( function ( resolve ) { window.setTimeout( resolve, 3000 ); } );
			const current = await request( 'GET' );
			draft = current && current.draft;
			if ( ! draft || Number( draft.id ) !== id ) {
				throw new Error( __( 'The metadata record changed or is no longer available. Check this post in Search Metadata.', 'ehrman-blog-discovery' ) );
			}
		}
		waiting = false;
		const url = new URL( config.pageUrl );
		url.searchParams.set( 'draft', String( id ) );
		window.location.replace( url.href );
		return true;
	}

	async function generate() {
		try {
			// Reuse any saved record instead of repeating a paid request after a retry or another tab.
			if ( await openRecord( await request( 'GET' ) ) ) {
				return;
			}
			if ( ! await openRecord( await request( 'POST', '/analyze' ) ) ) {
				throw new Error( __( 'The server did not return a metadata record.', 'ehrman-blog-discovery' ) );
			}
		} catch ( error ) {
			// Recover a saved record and follow its status without repeating generation.
			try {
				if ( await openRecord( await request( 'GET' ) ) ) {
					return;
				}
			} catch ( statusError ) {
				// Keep the original failure visible if status is also unavailable.
			}
			waiting = false;
			spinner.classList.remove( 'is-active' );
			status.textContent = __( 'Unable to confirm metadata generation.', 'ehrman-blog-discovery' );
			detail.textContent = __( 'Check this post in Search Metadata before trying again. No automatic retry was made.', 'ehrman-blog-discovery' );
			errorNotice.querySelector( 'p' ).textContent = error.message || __( 'The request could not be completed.', 'ehrman-blog-discovery' );
			errorNotice.hidden = false;
		}
	}

	// Allow the progress screen to paint before starting the foreground server request.
	window.requestAnimationFrame( function () { window.setTimeout( generate, 0 ); } );
}() );
