( function ( wp, config ) {
	'use strict';

	if ( ! wp || ! config || ! wp.editor || ! wp.editor.PluginDocumentSettingPanel ) {
		return;
	}

	const el = wp.element.createElement;
	const Fragment = wp.element.Fragment;
	const useEffect = wp.element.useEffect;
	const useState = wp.element.useState;
	const useRef = wp.element.useRef;
	const useSelect = wp.data.useSelect;
	const apiFetch = wp.apiFetch;
	const Button = wp.components.Button;
	const Notice = wp.components.Notice;
	const Spinner = wp.components.Spinner;
	const { TextareaControl } = wp.components;
	const PluginDocumentSettingPanel = wp.editor.PluginDocumentSettingPanel;
	const registerPlugin = wp.plugins.registerPlugin;
	const __ = wp.i18n.__;

	function readableStatus( value ) {
		if ( ! value ) {
			return __( 'Not started', 'ehrman-blog-discovery' );
		}
		return value
			.replace( /[_-]+/g, ' ' )
			.replace( /\b\w/g, function ( letter ) {
				return letter.toUpperCase();
			} );
	}

	function Detail( props ) {
		if ( ! props.value || ( Array.isArray( props.value ) && ! props.value.length ) ) {
			return null;
		}
		const value = Array.isArray( props.value ) ? props.value.join( ', ' ) : props.value;
		return el(
			'div',
			{ className: 'ehrman-ingestion-editor__detail' },
			el( 'strong', null, props.label ),
			el( 'p', null, value )
		);
	}

	function SearchMetadataPanel() {
		const editor = useSelect( function ( select ) {
			const store = select( 'core/editor' );
			return {
				postId: store.getCurrentPostId(),
				postStatus: store.getEditedPostAttribute( 'status' ),
				isDirty: store.isEditedPostDirty(),
				isSaving: store.isSavingPost(),
			};
		}, [] );
		const [ record, setRecord ] = useState( null );
		const [ loading, setLoading ] = useState( true );
		const [ busyAction, setBusyAction ] = useState( '' );
		const [ error, setError ] = useState( '' );
		const [ fields, setFields ] = useState( null );
		const [ revision, setRevision ] = useState( '' );
		const dirty = useRef( false );
		const requestId = useRef( 0 );
		const polling = useRef( false );
		const draft = record && record.draft;

		useEffect( function () {
			if ( ! dirty.current ) {
				setFields( draft ? {
					description: draft.description || '', search_summary: draft.searchSummary || '',
				} : null );
				setRevision( draft ? draft.revision : '' );
			}
		}, [ draft && draft.revision ] );

		useEffect( function () {
			function warn( event ) {
				if ( dirty.current || busyAction ) { event.preventDefault(); event.returnValue = ''; }
			}
			window.addEventListener( 'beforeunload', warn );
			return () => window.removeEventListener( 'beforeunload', warn );
		}, [ busyAction ] );

		function change( name, value ) {
			dirty.current = true;
			setFields( current => ( { ...current, [ name ]: value } ) );
		}

		function load( silent ) {
			if ( ! editor.postId ) {
				setLoading( false );
				return;
			}
			if ( ! silent ) {
				setLoading( true );
			}
			const ticket = ++requestId.current;
			return apiFetch( { path: config.restBase + editor.postId } )
				.then( function ( response ) {
					if ( ticket === requestId.current ) setRecord( response );
				} )
				.catch( function ( requestError ) {
					if ( ticket === requestId.current ) setError( requestError.message || __( 'Search metadata could not be loaded.', 'ehrman-blog-discovery' ) );
				} )
				.finally( function () {
					if ( ! silent && ticket === requestId.current ) {
						setLoading( false );
					}
				} );
		}

		useEffect( function () {
			dirty.current = false;
			setRecord( null );
			setError( '' );
			load( false );
			return () => { ++requestId.current; };
		}, [ editor.postId ] );

		useEffect( function () {
			if ( ! editor.isSaving && ! loading && ! busyAction ) load( true );
		}, [ editor.isSaving, editor.postStatus ] );

		useEffect( function () {
			if ( ! draft || ! draft.analysisActive || draft.analysisStalled || busyAction ) {
				return undefined;
			}
			const timer = window.setInterval( function () {
				if ( polling.current ) return;
				polling.current = true;
				Promise.resolve( load( true ) ).finally( () => { polling.current = false; } );
			}, 3000 );
			return function () {
				window.clearInterval( timer );
			};
		}, [ editor.postId, !! ( draft && draft.analysisActive ), !! ( draft && draft.analysisStalled ), busyAction ] );

		function perform( action, data ) {
			if ( 'reanalyze' === action && dirty.current && ! window.confirm( __( 'Discard these edits and regenerate metadata?', 'ehrman-blog-discovery' ) ) ) return;
			const ticket = ++requestId.current;
			setBusyAction( action );
			setError( '' );
			apiFetch( {
				path: config.restBase + editor.postId + '/' + action,
				method: 'POST',
				data: data || {},
			} )
				.then( function ( response ) {
					if ( ticket === requestId.current ) {
						dirty.current = false;
						setRecord( response );
					}
				} )
				.catch( function ( requestError ) {
					if ( ticket === requestId.current ) {
						setError( requestError.message || __( 'The requested action could not be completed. Reload metadata to check its status before trying again.', 'ehrman-blog-discovery' ) );
						if ( [ 'analyze', 'reanalyze' ].includes( action ) ) {
							return apiFetch( { path: config.restBase + editor.postId } ).then( response => {
								if ( ticket === requestId.current ) { dirty.current = false; setRecord( response ); }
							} ).catch( () => {} );
						}
					}
				} )
				.finally( function () {
					if ( ticket === requestId.current ) setBusyAction( '' );
				} );
		}

		function approve() {
			perform( 'approve', { revision, proposal: fields } );
		}

		function reload() {
			if ( dirty.current && ! window.confirm( __( 'Discard these edits and reload metadata?', 'ehrman-blog-discovery' ) ) ) return;
			dirty.current = false;
			setRecord( null );
			setError( '' );
			load( false );
		}

		const savedPostRequired = editor.isDirty || editor.isSaving;
		const publishRequired = 'publish' !== editor.postStatus;
		const actionDisabled = !! busyAction || savedPostRequired || publishRequired;
		const canAnalyze = record && ! draft;
		const canReanalyze = record && record.configured && draft && 'approved' !== draft.status &&
			( ! draft.analysisActive || draft.analysisStalled || 'queued' === draft.status );
		const needsReview = draft && ( 'ready' === draft.status || 'held' === draft.status );
		const changedReview = needsReview && revision !== draft.revision;
		const canApprove = needsReview && fields && 'ready' === draft.status && record.databaseAuthoritative &&
			! record.sourceChanged && ! changedReview;
		const keywords = draft ? [ ...( draft.secondaryKeywords || [] ), ...( draft.newSecondaryKeywords || [] ) ] : [];
		const generating = [ 'analyze', 'reanalyze' ].includes( busyAction );
		const canRetry = draft && 'approved' === draft.status &&
			'complete' !== draft.embeddingStatus && 'not_applicable' !== draft.embeddingStatus;

		let content;
		if ( loading ) {
			content = el( 'div', { className: 'ehrman-ingestion-editor__loading' }, el( Spinner ), __( 'Loading search metadata...', 'ehrman-blog-discovery' ) );
		} else {
			content = el(
				Fragment,
				null,
				error ? el( Notice, { status: 'error', isDismissible: false }, error ) : null,
				publishRequired ? el( Notice, { status: 'info', isDismissible: false }, __( 'Publish this post before creating metadata.', 'ehrman-blog-discovery' ) ) : savedPostRequired ? el( Notice, { status: 'warning', isDismissible: false }, __( 'Save your changes before continuing.', 'ehrman-blog-discovery' ) ) : null,
				record && ! record.configured ? el( Notice, { status: 'warning', isDismissible: false }, __( 'The shared OpenAI API key is not configured. Ask an administrator to set OPENAI_API_KEY on the server.', 'ehrman-blog-discovery' ) ) : null,
				record && ! record.databaseAuthoritative ? el( Notice, { status: 'info', isDismissible: false }, __( 'Review is available, but approval remains locked while JSON is authoritative.', 'ehrman-blog-discovery' ) ) : null,
				record && record.sourceChanged ? el( Notice, { status: 'warning', isDismissible: false }, __( 'The saved post changed. Regenerate metadata before approval.', 'ehrman-blog-discovery' ) ) : null,
				changedReview ? el( Notice, { status: 'warning', isDismissible: false }, __( 'The metadata changed elsewhere. Reload it before approval.', 'ehrman-blog-discovery' ) ) : null,
				( draft && draft.analysisActive && ! draft.analysisStalled ) || [ 'analyze', 'reanalyze' ].includes( busyAction ) ? el( 'div', { className: 'ehrman-ingestion-editor__loading', role: 'status' }, el( Spinner ), __( 'Creating metadata...', 'ehrman-blog-discovery' ) ) : null,
				[ 'approve', 'retry-embedding' ].includes( busyAction ) ? el( 'div', { className: 'ehrman-ingestion-editor__loading', role: 'status' }, el( Spinner ), 'approve' === busyAction ? __( 'Adding to search and creating vector...', 'ehrman-blog-discovery' ) : __( 'Creating vector...', 'ehrman-blog-discovery' ) ) : null,
				draft && draft.analysisStalled ? el( Notice, { status: 'warning', isDismissible: false }, __( 'Metadata creation stopped responding. Regenerate metadata to try again.', 'ehrman-blog-discovery' ) ) : null,
				draft && 'ready' === draft.status && ! generating ? el( 'p', { role: 'status' }, __( 'Metadata is ready for review.', 'ehrman-blog-discovery' ) ) : null,
				draft && 'approved' === draft.status ? el( Notice, { status: 'success', isDismissible: false }, __( 'Added to Search', 'ehrman-blog-discovery' ) ) : null,
				draft && 'held' === draft.status ? el( Notice, { status: 'warning', isDismissible: false }, __( 'Metadata could not be approved. Check the warnings below and regenerate metadata after correcting the post or vocabulary.', 'ehrman-blog-discovery' ), el( 'ul', null, ( draft.reviewNotes || [] ).map( ( note, index ) => el( 'li', { key: index }, note ) ) ) ) : null,
				draft && 'approved' === draft.status ? el(
					'dl',
					{ className: 'ehrman-ingestion-editor__status' },
					el( 'div', null, el( 'dt', null, __( 'Workflow', 'ehrman-blog-discovery' ) ), el( 'dd', null, readableStatus( draft && draft.status ) ) ),
					draft ? el( 'div', null, el( 'dt', null, __( 'Vector', 'ehrman-blog-discovery' ) ), el( 'dd', null, readableStatus( draft.embeddingStatus ) ) ) : null
				) : null,
				draft && draft.errorMessage ? el( Notice, { status: 'error', isDismissible: false }, draft.errorMessage ) : null,
				draft && draft.embeddingError ? el( Notice, { status: 'warning', isDismissible: false }, draft.embeddingError ) : null,
				needsReview && fields && ! generating ? el( 'fieldset', { className: 'ehrman-ingestion-editor__fields', disabled: !! busyAction },
					el( TextareaControl, { label: __( 'Description', 'ehrman-blog-discovery' ), value: fields.description, rows: 4, onChange: value => change( 'description', value ) } ),
					el( TextareaControl, { label: __( 'Summary', 'ehrman-blog-discovery' ), value: fields.search_summary, rows: 8, onChange: value => change( 'search_summary', value ) } ),
					el( Detail, { label: __( 'Topics', 'ehrman-blog-discovery' ), value: draft.topics } ),
					el( Detail, { label: __( 'Keywords', 'ehrman-blog-discovery' ), value: keywords } )
				) : null,
				! needsReview ? el( Fragment, null,
				el( Detail, { label: __( 'Description', 'ehrman-blog-discovery' ), value: draft && draft.description } ),
				el( Detail, { label: __( 'Summary', 'ehrman-blog-discovery' ), value: draft && draft.searchSummary } ),
				el( Detail, { label: __( 'Topics', 'ehrman-blog-discovery' ), value: draft && draft.topics } ),
				el( Detail, { label: __( 'Keywords', 'ehrman-blog-discovery' ), value: keywords } ),
				) : null,
				el(
					'div',
					{ className: 'ehrman-ingestion-editor__actions' },
					canAnalyze ? el( Button, {
						variant: 'primary',
						disabled: actionDisabled || ! record.configured,
						onClick: function () { perform( 'analyze' ); },
					}, __( 'Create Metadata', 'ehrman-blog-discovery' ) ) : null,
					needsReview && ! generating ? el( Button, { variant: 'primary', disabled: actionDisabled || ! canApprove, onClick: approve }, __( 'Approve and Add to Search', 'ehrman-blog-discovery' ) ) : null,
					canReanalyze ? el( Button, {
						variant: 'secondary',
						disabled: actionDisabled,
						onClick: function () { perform( 'reanalyze' ); },
					}, __( 'Regenerate Metadata', 'ehrman-blog-discovery' ) ) : null,
					canRetry ? el( Button, {
						variant: 'secondary',
						disabled: actionDisabled,
						onClick: function () { perform( 'retry-embedding' ); },
					}, 'retry-embedding' === busyAction ? __( 'Retrying...', 'ehrman-blog-discovery' ) : __( 'Retry Vector', 'ehrman-blog-discovery' ) ) : null,
					error || changedReview ? el( Button, { variant: 'secondary', disabled: !! busyAction, onClick: reload }, __( 'Reload Metadata', 'ehrman-blog-discovery' ) ) : null
				),
				record ? el( Button, { variant: 'link', className: 'ehrman-ingestion-editor__history', href: record.reviewUrl }, __( 'Search Metadata', 'ehrman-blog-discovery' ) ) : null
			);
		}

		return el(
			PluginDocumentSettingPanel,
			{
				name: 'ehrman-search-metadata',
				title: __( 'Search Metadata', 'ehrman-blog-discovery' ),
				className: 'ehrman-ingestion-editor',
			},
			content
		);
	}

	registerPlugin( 'ehrman-search-metadata', {
		render: SearchMetadataPanel,
	} );
}( window.wp, window.EhrmanPostIngestion ) );
