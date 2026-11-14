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

	function openRecord( result ) {
		const id = Number( result && result.draft && result.draft.id );
		if ( ! Number.isSafeInteger( id ) || id <= 0 ) {
			return false;
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
			if ( openRecord( await request( 'GET' ) ) ) {
				return;
			}
			if ( ! openRecord( await request( 'POST', '/analyze' ) ) ) {
				throw new Error( __( 'The server did not return a metadata record.', 'ehrman-blog-discovery' ) );
			}
		} catch ( error ) {
			// A timeout can leave a saved record. Recover it once, without repeating generation.
			try {
				if ( openRecord( await request( 'GET' ) ) ) {
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
