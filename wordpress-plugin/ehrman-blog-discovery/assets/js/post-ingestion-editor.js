( function ( wp, config ) {
	'use strict';

	if ( ! wp || ! config || ! wp.editor || ! wp.editor.PluginDocumentSettingPanel ) {
		return;
	}

	const el = wp.element.createElement;
	const Fragment = wp.element.Fragment;
	const useEffect = wp.element.useEffect;
	const useState = wp.element.useState;
	const useSelect = wp.data.useSelect;
	const apiFetch = wp.apiFetch;
	const Button = wp.components.Button;
	const CheckboxControl = wp.components.CheckboxControl;
	const Notice = wp.components.Notice;
	const Spinner = wp.components.Spinner;
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
		const [ approveNewKeywords, setApproveNewKeywords ] = useState( false );
		const draft = record && record.draft;

		function load( silent ) {
			if ( ! editor.postId ) {
				setLoading( false );
				return;
			}
			if ( ! silent ) {
				setLoading( true );
			}
			setError( '' );
			apiFetch( { path: config.restBase + editor.postId } )
				.then( function ( response ) {
					setRecord( response );
				} )
				.catch( function ( requestError ) {
					setError( requestError.message || __( 'Search metadata could not be loaded.', 'ehrman-blog-discovery' ) );
				} )
				.finally( function () {
					if ( ! silent ) {
						setLoading( false );
					}
				} );
		}

		useEffect( function () {
			load( false );
		}, [ editor.postId ] );

		useEffect( function () {
			if ( ! draft || ! draft.analysisActive || draft.analysisStalled ) {
				return undefined;
			}
			const timer = window.setInterval( function () {
				load( true );
			}, 3000 );
			return function () {
				window.clearInterval( timer );
			};
		}, [ editor.postId, !! ( draft && draft.analysisActive ), !! ( draft && draft.analysisStalled ) ] );

		function perform( action, data ) {
			setBusyAction( action );
			setError( '' );
			apiFetch( {
				path: config.restBase + editor.postId + '/' + action,
				method: 'POST',
				data: data || {},
			} )
				.then( function ( response ) {
					setRecord( response );
					setApproveNewKeywords( false );
				} )
				.catch( function ( requestError ) {
					setError( requestError.message || __( 'The requested action could not be completed.', 'ehrman-blog-discovery' ) );
				} )
				.finally( function () {
					setBusyAction( '' );
				} );
		}

		const hasNewKeywords = !! ( draft && draft.newSecondaryKeywords && draft.newSecondaryKeywords.length );
		const savedPostRequired = editor.isDirty || editor.isSaving;
		const actionDisabled = !! busyAction || savedPostRequired;
		const canAnalyze = record && record.configured && 'publish' === editor.postStatus && ! draft;
		const canReanalyze = record && record.configured && draft && 'approved' !== draft.status &&
			( ! draft.analysisActive || draft.analysisStalled );
		const canApprove = record && record.databaseAuthoritative && ! record.sourceChanged && draft && 'ready' === draft.status;
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
				savedPostRequired ? el( Notice, { status: 'warning', isDismissible: false }, __( 'Save or update the post before running a search-metadata action.', 'ehrman-blog-discovery' ) ) : null,
				record && ! draft && 'publish' !== editor.postStatus ? el( Notice, { status: 'info', isDismissible: false }, __( 'Publish the post before generating search metadata.', 'ehrman-blog-discovery' ) ) : null,
				record && ! record.configured ? el( Notice, { status: 'warning', isDismissible: false }, __( 'The ingestion API key is not configured.', 'ehrman-blog-discovery' ) ) : null,
				record && ! record.databaseAuthoritative ? el( Notice, { status: 'info', isDismissible: false }, __( 'Review is available, but approval remains locked while JSON is authoritative.', 'ehrman-blog-discovery' ) ) : null,
				record && record.sourceChanged ? el( Notice, { status: 'warning', isDismissible: false }, __( 'The saved post changed after analysis. Reanalyze it before approval.', 'ehrman-blog-discovery' ) ) : null,
				draft && draft.analysisActive && ! draft.analysisStalled ? el( Notice, { status: 'info', isDismissible: false }, __( 'Analysis is running in the background. You may continue editing or leave this page; results will appear automatically.', 'ehrman-blog-discovery' ) ) : null,
				draft && draft.analysisStalled ? el( Notice, { status: 'warning', isDismissible: false }, __( 'Analysis appears to have stopped. Retry it to start a fresh background attempt.', 'ehrman-blog-discovery' ) ) : null,
				el(
					'dl',
					{ className: 'ehrman-ingestion-editor__status' },
					el( 'div', null, el( 'dt', null, __( 'Workflow', 'ehrman-blog-discovery' ) ), el( 'dd', null, readableStatus( draft && draft.status ) ) ),
					draft ? el( 'div', null, el( 'dt', null, __( 'Vector', 'ehrman-blog-discovery' ) ), el( 'dd', null, readableStatus( draft.embeddingStatus ) ) ) : null
				),
				draft && draft.errorMessage ? el( Notice, { status: 'error', isDismissible: false }, draft.errorMessage ) : null,
				draft && draft.embeddingError ? el( Notice, { status: 'warning', isDismissible: false }, draft.embeddingError ) : null,
				el( Detail, { label: __( 'Description', 'ehrman-blog-discovery' ), value: draft && draft.description } ),
				el( Detail, { label: __( 'Search summary', 'ehrman-blog-discovery' ), value: draft && draft.searchSummary } ),
				el( Detail, { label: __( 'Topics', 'ehrman-blog-discovery' ), value: draft && draft.topics } ),
				el( Detail, { label: __( 'Secondary keywords', 'ehrman-blog-discovery' ), value: draft && draft.secondaryKeywords } ),
				el( Detail, { label: __( 'New secondary keywords', 'ehrman-blog-discovery' ), value: draft && draft.newSecondaryKeywords } ),
				el( Detail, { label: __( 'Review notes', 'ehrman-blog-discovery' ), value: draft && draft.reviewNotes } ),
				hasNewKeywords && canApprove ? el( CheckboxControl, {
					label: __( 'Approve creation of the proposed new secondary keywords', 'ehrman-blog-discovery' ),
					checked: approveNewKeywords,
					onChange: setApproveNewKeywords,
				} ) : null,
				el(
					'div',
					{ className: 'ehrman-ingestion-editor__actions' },
					canAnalyze ? el( Button, {
						variant: 'primary',
						disabled: actionDisabled,
						onClick: function () { perform( 'analyze' ); },
					}, 'analyze' === busyAction ? __( 'Queueing...', 'ehrman-blog-discovery' ) : __( 'Analyze', 'ehrman-blog-discovery' ) ) : null,
					draft ? el( Button, { variant: 'secondary', href: record.reviewUrl }, __( 'Review', 'ehrman-blog-discovery' ) ) : null,
					canReanalyze ? el( Button, {
						variant: 'secondary',
						disabled: actionDisabled,
						onClick: function () { perform( 'reanalyze' ); },
					}, 'reanalyze' === busyAction ? __( 'Queueing...', 'ehrman-blog-discovery' ) : ( draft.analysisStalled || 'error' === draft.status ? __( 'Retry Analysis', 'ehrman-blog-discovery' ) : __( 'Reanalyze', 'ehrman-blog-discovery' ) ) ) : null,
					canApprove ? el( Button, {
						variant: 'primary',
						disabled: actionDisabled || ( hasNewKeywords && ! approveNewKeywords ),
						onClick: function () { perform( 'approve', { approve_new_keywords: approveNewKeywords } ); },
					}, 'approve' === busyAction ? __( 'Approving...', 'ehrman-blog-discovery' ) : __( 'Approve', 'ehrman-blog-discovery' ) ) : null,
					canRetry ? el( Button, {
						variant: 'secondary',
						disabled: actionDisabled,
						onClick: function () { perform( 'retry-embedding' ); },
					}, 'retry-embedding' === busyAction ? __( 'Retrying...', 'ehrman-blog-discovery' ) : __( 'Retry Vector', 'ehrman-blog-discovery' ) ) : null,
					! draft && record ? el( Button, { variant: 'tertiary', href: record.reviewUrl }, __( 'Open ingestion history', 'ehrman-blog-discovery' ) ) : null
				)
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
