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
	const { CheckboxControl, FormTokenField, TextareaControl } = wp.components;
	const PluginDocumentSettingPanel = wp.editor.PluginDocumentSettingPanel;
	const registerPlugin = wp.plugins.registerPlugin;
	const __ = wp.i18n.__;
	const topics = ( config.topics || [] ).slice().sort( ( a, b ) => a.localeCompare( b ) );
	const keywords = ( config.keywords || [] ).slice().sort( ( a, b ) => a.localeCompare( b ) );

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
		const [ approveNew, setApproveNew ] = useState( false );
		const dirty = useRef( false );
		const requestId = useRef( 0 );
		const polling = useRef( false );
		const draft = record && record.draft;

		useEffect( function () {
			if ( ! dirty.current ) {
				setFields( draft ? {
					description: draft.description || '', search_summary: draft.searchSummary || '',
					topics: draft.topics || [], secondary_keywords: draft.secondaryKeywords || [],
					new_secondary_keywords: draft.newSecondaryKeywords || [],
					review_notes: ( draft.reviewNotes || [] ).join( '\n' ), status: draft.status,
				} : null );
				setRevision( draft ? draft.revision : '' );
				setApproveNew( false );
			}
		}, [ draft && draft.revision ] );

		useEffect( function () {
			function warn( event ) {
				if ( dirty.current ) { event.preventDefault(); event.returnValue = ''; }
			}
			window.addEventListener( 'beforeunload', warn );
			return () => window.removeEventListener( 'beforeunload', warn );
		}, [] );

		function change( name, value ) {
			dirty.current = true;
			setFields( current => ( { ...current, [ name ]: value } ) );
			if ( 'new_secondary_keywords' === name ) setApproveNew( false );
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
					if ( ticket === requestId.current ) setError( requestError.message || __( 'The requested action could not be completed.', 'ehrman-blog-discovery' ) );
				} )
				.finally( function () {
					if ( ticket === requestId.current ) setBusyAction( '' );
				} );
		}

		function approve() {
			perform( 'approve', { revision, approve_new_keywords: approveNew, proposal: {
				...fields, secondary_keywords: fields.secondary_keywords.join( '\n' ),
				new_secondary_keywords: fields.new_secondary_keywords.join( '\n' ),
			} } );
		}

		function reload() {
			if ( dirty.current && ! window.confirm( __( 'Discard these edits and reload metadata?', 'ehrman-blog-discovery' ) ) ) return;
			dirty.current = false;
			setRecord( null );
			setError( '' );
			load( false );
		}

		function tokens( name, label, suggestions ) {
			return el( FormTokenField, { label, value: fields[ name ], suggestions, disabled: !! busyAction,
				__experimentalValidateInput: value => suggestions.includes( value ),
				onChange: values => change( name, values.filter( value => suggestions.includes( value ) ) ),
			} );
		}

		const savedPostRequired = editor.isDirty || editor.isSaving;
		const publishRequired = 'publish' !== editor.postStatus;
		const actionDisabled = !! busyAction || savedPostRequired || publishRequired;
		const canAnalyze = record && ! draft;
		const canReanalyze = record && record.configured && draft && 'approved' !== draft.status &&
			( ! draft.analysisActive || draft.analysisStalled );
		const needsReview = draft && ( 'ready' === draft.status || 'held' === draft.status );
		const changedReview = needsReview && revision !== draft.revision;
		const canApprove = needsReview && fields && 'ready' === fields.status && record.databaseAuthoritative &&
			! record.sourceChanged && ! changedReview && ( ! fields.new_secondary_keywords.length || approveNew );
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
				draft && 'ready' === draft.status ? el( 'p', { role: 'status' }, __( 'Metadata is ready for review.', 'ehrman-blog-discovery' ) ) : null,
				draft && 'approved' === draft.status ? el( Notice, { status: 'success', isDismissible: false }, __( 'Added to Search', 'ehrman-blog-discovery' ) ) : null,
				draft && 'held' === draft.status ? el( Notice, { status: 'warning', isDismissible: false }, __( 'Search metadata requires review. Resolve the review notes and mark the proposal ready before approval.', 'ehrman-blog-discovery' ) ) : null,
				draft && 'approved' === draft.status ? el(
					'dl',
					{ className: 'ehrman-ingestion-editor__status' },
					el( 'div', null, el( 'dt', null, __( 'Workflow', 'ehrman-blog-discovery' ) ), el( 'dd', null, readableStatus( draft && draft.status ) ) ),
					draft ? el( 'div', null, el( 'dt', null, __( 'Vector', 'ehrman-blog-discovery' ) ), el( 'dd', null, readableStatus( draft.embeddingStatus ) ) ) : null
				) : null,
				draft && draft.errorMessage ? el( Notice, { status: 'error', isDismissible: false }, draft.errorMessage ) : null,
				draft && draft.embeddingError ? el( Notice, { status: 'warning', isDismissible: false }, draft.embeddingError ) : null,
				needsReview && fields ? el( 'fieldset', { className: 'ehrman-ingestion-editor__fields', disabled: !! busyAction },
					el( TextareaControl, { label: __( 'Description', 'ehrman-blog-discovery' ), value: fields.description, rows: 4, onChange: value => change( 'description', value ) } ),
					el( TextareaControl, { label: __( 'Search Summary', 'ehrman-blog-discovery' ), value: fields.search_summary, rows: 8, onChange: value => change( 'search_summary', value ) } ),
					tokens( 'topics', __( 'Topics', 'ehrman-blog-discovery' ), topics ),
					tokens( 'secondary_keywords', __( 'Secondary Keywords', 'ehrman-blog-discovery' ), keywords ),
					( fields.new_secondary_keywords.length || draft.newSecondaryKeywords.length ) ? el( Fragment, null,
						el( FormTokenField, { label: __( 'Proposed New Keywords', 'ehrman-blog-discovery' ), value: fields.new_secondary_keywords, disabled: !! busyAction, onChange: values => change( 'new_secondary_keywords', values ) } ),
						fields.new_secondary_keywords.length ? el( CheckboxControl, { label: __( 'Approve these new keyword labels', 'ehrman-blog-discovery' ), checked: approveNew, onChange: setApproveNew } ) : null
					) : null,
					fields.review_notes || 'held' === draft.status ? el( TextareaControl, { label: __( 'Review Notes and Warnings', 'ehrman-blog-discovery' ), value: fields.review_notes, rows: 5, onChange: value => change( 'review_notes', value ) } ) : null,
					'held' === draft.status ? el( CheckboxControl, { label: __( 'Review issues resolved; metadata is ready', 'ehrman-blog-discovery' ), checked: 'ready' === fields.status, onChange: checked => change( 'status', checked ? 'ready' : 'held' ) } ) : null
				) : null,
				! needsReview ? el( Fragment, null,
				el( Detail, { label: __( 'Description', 'ehrman-blog-discovery' ), value: draft && draft.description } ),
				el( Detail, { label: __( 'Search summary', 'ehrman-blog-discovery' ), value: draft && draft.searchSummary } ),
				el( Detail, { label: __( 'Topics', 'ehrman-blog-discovery' ), value: draft && draft.topics } ),
				el( Detail, { label: __( 'Secondary keywords', 'ehrman-blog-discovery' ), value: draft && draft.secondaryKeywords } ),
				el( Detail, { label: __( 'New secondary keywords', 'ehrman-blog-discovery' ), value: draft && draft.newSecondaryKeywords } ),
				el( Detail, { label: __( 'Review notes', 'ehrman-blog-discovery' ), value: draft && draft.reviewNotes } ),
				) : null,
				el(
					'div',
					{ className: 'ehrman-ingestion-editor__actions' },
					canAnalyze ? el( Button, {
						variant: 'primary',
						disabled: actionDisabled || ! record.configured,
						onClick: function () { perform( 'analyze' ); },
					}, __( 'Create Metadata', 'ehrman-blog-discovery' ) ) : null,
					needsReview ? el( Button, { variant: 'primary', disabled: actionDisabled || ! canApprove, onClick: approve }, __( 'Approve and Add to Search', 'ehrman-blog-discovery' ) ) : null,
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
				record ? el( Button, { variant: 'link', className: 'ehrman-ingestion-editor__history', href: record.reviewUrl }, __( 'Ingestion history', 'ehrman-blog-discovery' ) ) : null
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
