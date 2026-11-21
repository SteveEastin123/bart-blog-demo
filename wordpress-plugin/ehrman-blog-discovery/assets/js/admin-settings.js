( function () {
	'use strict';

	const config = window.EhrmanAdminSettings;
	if ( ! config || ! window.wp?.apiFetch || ! window.wp?.i18n ) {
		return;
	}
	const __ = window.wp.i18n.__;

	function request( method, data ) {
		return window.wp.apiFetch( {
			path: config.restPath,
			method: method,
			data: data,
			headers: { 'X-WP-Nonce': config.nonce },
		} );
	}

	function connect( group ) {
		const panel = document.querySelector( group.selector );
		if ( ! panel ) {
			return;
		}
		const fieldset = panel.querySelector( '[data-ebd-setting-options]' );
		const radios = Array.from( fieldset.querySelectorAll( 'input[type="radio"]' ) );
		const status = panel.querySelector( '[data-ebd-setting-status]' );
		const errorNotice = panel.querySelector( '[data-ebd-setting-error]' );
		let savedMode = radios.find( radio => radio.checked ).value;
		let saving = false;

		function modeFrom( result ) {
			const mode = result?.[ group.option ];
			if ( ! group.values.includes( mode ) ) {
				throw new Error( 'Invalid setting response.' );
			}
			return mode;
		}

		async function save( event ) {
			const mode = event.target.value;
			if ( saving || ! event.target.checked || mode === savedMode ) {
				return;
			}
			saving = true;
			fieldset.disabled = true;
			fieldset.setAttribute( 'aria-busy', 'true' );
			errorNotice.hidden = true;
			status.textContent = __( 'Saving...', 'ehrman-blog-discovery' );
			let confirmed = false;
			try {
				savedMode = modeFrom( await request( 'POST', { [ group.option ]: mode } ) );
				confirmed = true;
			} catch ( error ) {
				// A lost response can follow a successful save; check before restoring the selection.
				try {
					savedMode = modeFrom( await request( 'GET' ) );
					confirmed = true;
				} catch ( recoveryError ) {
					// Keep the last confirmed selection when the server cannot be reached.
				}
			} finally {
				for ( const radio of radios ) {
					radio.checked = radio.value === savedMode;
				}
				if ( confirmed && savedMode === mode ) {
					status.textContent = __( 'Saved', 'ehrman-blog-discovery' );
				} else {
					status.textContent = '';
					errorNotice.querySelector( 'p' ).textContent = confirmed
						? __( 'The change could not be saved. The current setting has been restored. Please try again.', 'ehrman-blog-discovery' )
						: __( 'The save could not be confirmed. The previous selection is shown. Reload this page to check the setting before continuing.', 'ehrman-blog-discovery' );
					errorNotice.hidden = false;
				}
				fieldset.disabled = false;
				fieldset.setAttribute( 'aria-busy', 'false' );
				saving = false;
			}
		}

		radios.forEach( radio => radio.addEventListener( 'change', save ) );
		fieldset.disabled = false;
		window.addEventListener( 'beforeunload', function ( event ) {
			if ( saving ) {
				event.preventDefault();
				event.returnValue = '';
			}
		} );
	}

	config.groups.forEach( connect );
} )();
