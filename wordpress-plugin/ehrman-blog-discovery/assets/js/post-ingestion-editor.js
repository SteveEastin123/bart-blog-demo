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
	const { TextareaControl, SelectControl } = wp.components;
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

	function TermSelections( props ) {
		const group = useRef( null );
		const focusLabel = useRef( null );
		const pickerHost = useRef( null );
		const picker = useRef( null );
		const current = useRef( props );
		current.current = props;
		useEffect( function () {
			picker.current = window.EhrmanMetadataTermPicker( pickerHost.current, {
				kind: props.kind,
				selected: () => current.current.selected,
				onSelect: value => current.current.onAdd( value ),
			} );
			return () => picker.current.destroy();
		}, [] );
		useEffect( function () {
			picker.current.setDisabled( props.disabled );
			picker.current.refresh();
		}, [ props.values, props.removed, props.disabled ] );
		useEffect( function () {
			if ( focusLabel.current && group.current ) {
				const button = Array.from( group.current.querySelectorAll( 'button' ) ).find( item => item.dataset.termLabel === focusLabel.current );
				if ( button ) button.focus();
				else pickerHost.current.querySelector( 'button' ).focus();
				focusLabel.current = null;
			}
		}, [ props.removed, props.values ] );
		function terms( removed ) {
			return el( 'ul', { className: 'ehrman-metadata-selections__list' }, props.values.filter( value => props.removed.includes( value ) === removed ).map( value => {
				const removeLabel = props.kind === 'topic' ? __( 'Remove topic: %s', 'ehrman-blog-discovery' ) : __( 'Remove keyword: %s', 'ehrman-blog-discovery' );
				const restoreLabel = props.kind === 'topic' ? __( 'Restore topic: %s', 'ehrman-blog-discovery' ) : __( 'Restore keyword: %s', 'ehrman-blog-discovery' );
				const label = wp.i18n.sprintf( removed ? restoreLabel : removeLabel, value );
				return el( 'li', { key: value, className: 'ehrman-metadata-selections__term' + ( removed ? ' is-removed' : '' ) },
					el( 'span', { className: 'ehrman-metadata-selections__label' }, value ),
					el( Button, { type: 'button', className: 'ehrman-metadata-selections__button', icon: removed ? 'undo' : 'no-alt',
						label, 'aria-label': label, title: label, 'data-term-label': value, disabled: props.disabled,
						onClick: () => { focusLabel.current = value; props.onToggle( value ); },
					} )
				);
			} ) );
		}
		return el( 'div', { ref: group, className: 'ehrman-metadata-selections', role: 'group', 'aria-label': props.label },
			el( 'strong', null, props.label ), terms( false ),
			props.removed.length ? el( 'div', null, el( 'span', null, __( 'Removed', 'ehrman-blog-discovery' ) ), terms( true ) ) : null,
			el( 'div', { ref: pickerHost } )
		);
	}

	function SearchMetadataPanel() {
		const editor = useSelect( function ( select ) {
			const store = select( 'core/editor' );
			return {
				postId: store.getCurrentPostId(),
				postStatus: store.getEditedPostAttribute( 'status' ),
				meta: store.getEditedPostAttribute( 'meta' ) || {},
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
		const modeKey = config.modeMeta || '_ehrman_metadata_mode';
		const mode = editor.meta[ modeKey ] || 'inherit';
		const effectiveMode = mode === 'inherit' ? ( config.defaultMode || 'manual' ) : mode;
		const automaticBusy = !! ( draft && draft.automaticActive );

		useEffect( function () {
			if ( ! dirty.current ) {
				setFields( draft ? {
					description: draft.description || '', search_summary: draft.searchSummary || '',
					removed_topics: [], removed_keywords: [], added_topics: [], added_keywords: [],
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

		function toggleTerm( name, value ) {
			dirty.current = true;
			setFields( current => ( { ...current, [ name ]: current[ name ].includes( value ) ? current[ name ].filter( item => item !== value ) : [ ...current[ name ], value ] } ) );
		}

		function addTerm( kind, value ) {
			const suffix = kind === 'topic' ? 'topics' : 'keywords';
			const original = kind === 'topic' ? draft.topics || [] : keywords;
			dirty.current = true;
			setFields( current => ( { ...current,
				[ 'removed_' + suffix ]: current[ 'removed_' + suffix ].filter( item => item !== value ),
				[ 'added_' + suffix ]: original.includes( value ) ? current[ 'added_' + suffix ] : [ ...new Set( [ ...current[ 'added_' + suffix ], value ] ) ],
			} ) );
		}

		function toggleSelection( kind, value ) {
			const suffix = kind === 'topic' ? 'topics' : 'keywords';
			if ( fields[ 'added_' + suffix ].includes( value ) ) {
				change( 'added_' + suffix, fields[ 'added_' + suffix ].filter( item => item !== value ) );
			} else toggleTerm( 'removed_' + suffix, value );
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
			if ( 'reanalyze' === action && ! window.confirm( __( 'Regenerate metadata using AI? This incurs an AI charge and replaces any unapproved edits. Approved metadata stays active until the new version is approved.', 'ehrman-blog-discovery' ) ) ) return;
			if ( 'cancel-revision' === action && ! window.confirm( __( 'Cancel these changes and keep the approved metadata?', 'ehrman-blog-discovery' ) ) ) return;
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
		const actionDisabled = !! busyAction || automaticBusy || savedPostRequired || publishRequired;
		const canAnalyze = record && ! draft;
		const canReanalyze = record && record.configured && draft &&
			( ! draft.analysisActive || draft.analysisStalled || 'queued' === draft.status );
		const needsReview = draft && ! automaticBusy && ( 'ready' === draft.status || 'held' === draft.status );
		const changedReview = needsReview && revision !== draft.revision;
		const missingTopics = needsReview && fields && 'ready' === draft.status && ! fields.added_topics.length && ! ( draft.topics || [] ).some( topic => ! fields.removed_topics.includes( topic ) );
		const canApprove = needsReview && fields && 'ready' === draft.status && record.databaseAuthoritative &&
			! record.sourceChanged && ! changedReview && ! missingTopics;
		const keywords = draft ? [ ...( draft.secondaryKeywords || [] ), ...( draft.newSecondaryKeywords || [] ) ] : [];
		const selectedTerms = fields && draft ? [ ...( draft.topics || [] ).filter( value => ! fields.removed_topics.includes( value ) ), ...keywords.filter( value => ! fields.removed_keywords.includes( value ) ), ...fields.added_topics, ...fields.added_keywords ] : [];
		const generating = [ 'analyze', 'reanalyze' ].includes( busyAction );
		const pendingRevision = draft && draft.isRevision && 'approved' !== draft.status;
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
				record && record.automaticError ? el( Notice, { status: 'error', isDismissible: false }, record.automaticError ) : null,
				publishRequired ? el( Notice, { status: 'info', isDismissible: false }, effectiveMode === 'automatic' && ! ( record && record.hasPublished ) ? __( 'Metadata will be created and approved automatically after publication.', 'ehrman-blog-discovery' ) : __( 'Publish this post before creating metadata.', 'ehrman-blog-discovery' ) ) : savedPostRequired ? el( Notice, { status: 'warning', isDismissible: false }, __( 'Save your changes before continuing.', 'ehrman-blog-discovery' ) ) : null,
				record && ! record.configured ? el( Notice, { status: 'warning', isDismissible: false }, __( 'The shared OpenAI API key is not configured. Ask an administrator to set OPENAI_API_KEY on the server.', 'ehrman-blog-discovery' ) ) : null,
				record && ! record.databaseAuthoritative ? el( Notice, { status: 'info', isDismissible: false }, __( 'Review is available, but approval remains locked while JSON is authoritative.', 'ehrman-blog-discovery' ) ) : null,
				record && record.sourceChanged ? el( Notice, { status: 'warning', isDismissible: false }, __( 'The saved post changed. Regenerate metadata before approval.', 'ehrman-blog-discovery' ) ) : null,
				changedReview ? el( Notice, { status: 'warning', isDismissible: false }, __( 'The metadata changed elsewhere. Reload it before approval.', 'ehrman-blog-discovery' ) ) : null,
				pendingRevision ? el( Notice, { status: 'info', isDismissible: false }, __( 'Approved metadata remains active until these changes are approved.', 'ehrman-blog-discovery' ) ) : null,
				( draft && draft.analysisActive && ! draft.analysisStalled ) || [ 'analyze', 'reanalyze' ].includes( busyAction ) ? el( 'div', { className: 'ehrman-ingestion-editor__loading', role: 'status' }, el( Spinner ), automaticBusy && draft.status === 'approved' ? __( 'Creating vector...', 'ehrman-blog-discovery' ) : __( 'Creating metadata...', 'ehrman-blog-discovery' ) ) : null,
				[ 'approve', 'retry-embedding' ].includes( busyAction ) ? el( 'div', { className: 'ehrman-ingestion-editor__loading', role: 'status' }, el( Spinner ), 'approve' === busyAction ? __( 'Adding to search and creating vector...', 'ehrman-blog-discovery' ) : __( 'Creating vector...', 'ehrman-blog-discovery' ) ) : null,
				draft && draft.analysisStalled ? el( Notice, { status: 'warning', isDismissible: false }, __( 'Metadata creation stopped responding. Regenerate metadata to try again.', 'ehrman-blog-discovery' ) ) : null,
				draft && 'ready' === draft.status && ! generating && ! automaticBusy ? el( 'p', { role: 'status' }, __( 'Metadata is ready for review.', 'ehrman-blog-discovery' ) ) : null,
				draft && 'approved' === draft.status && ! automaticBusy ? el( Notice, { status: 'success', isDismissible: false }, __( 'Added to Search', 'ehrman-blog-discovery' ) ) : null,
				draft && 'held' === draft.status ? el( Notice, { status: 'warning', isDismissible: false }, __( 'Metadata could not be approved. Check the warnings below and regenerate metadata after correcting the post or vocabulary.', 'ehrman-blog-discovery' ), el( 'ul', null, ( draft.reviewNotes || [] ).map( ( note, index ) => el( 'li', { key: index }, note ) ) ) ) : null,
				draft && 'approved' === draft.status ? el(
					'dl',
					{ className: 'ehrman-ingestion-editor__status' },
					el( 'div', null, el( 'dt', null, __( 'Workflow', 'ehrman-blog-discovery' ) ), el( 'dd', null, readableStatus( draft && draft.status ) ) ),
					el( 'div', null, el( 'dt', null, __( 'Approval', 'ehrman-blog-discovery' ) ), el( 'dd', null, readableStatus( draft.approvalMode || 'manual' ) ) ),
					draft ? el( 'div', null, el( 'dt', null, __( 'Vector', 'ehrman-blog-discovery' ) ), el( 'dd', null, readableStatus( draft.embeddingStatus ) ) ) : null
				) : null,
				draft && draft.errorMessage ? el( Notice, { status: 'error', isDismissible: false }, draft.errorMessage ) : null,
				draft && draft.embeddingError ? el( Notice, { status: 'warning', isDismissible: false }, draft.embeddingError ) : null,
				needsReview && fields && ! generating ? el( 'fieldset', { className: 'ehrman-ingestion-editor__fields', disabled: !! busyAction },
					el( TextareaControl, { label: __( 'Description', 'ehrman-blog-discovery' ), value: fields.description, rows: 4, onChange: value => change( 'description', value ) } ),
					el( TextareaControl, { label: __( 'Summary', 'ehrman-blog-discovery' ), value: fields.search_summary, rows: 8, onChange: value => change( 'search_summary', value ) } ),
					el( TermSelections, { label: __( 'Topics', 'ehrman-blog-discovery' ), kind: 'topic', values: [ ...( draft.topics || [] ), ...fields.added_topics ], selected: selectedTerms, removed: fields.removed_topics, disabled: !! busyAction, onToggle: value => toggleSelection( 'topic', value ), onAdd: value => addTerm( 'topic', value ) } ),
					el( TermSelections, { label: __( 'Keywords', 'ehrman-blog-discovery' ), kind: 'keyword', values: [ ...keywords, ...fields.added_keywords ], selected: selectedTerms, removed: fields.removed_keywords, disabled: !! busyAction, onToggle: value => toggleSelection( 'keyword', value ), onAdd: value => addTerm( 'keyword', value ) } ),
					missingTopics ? el( Notice, { status: 'warning', isDismissible: false }, __( 'Keep at least one topic. Add an existing topic or restore a removed topic.', 'ehrman-blog-discovery' ) ) : null
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
					draft && 'approved' === draft.status ? el( Button, { variant: 'secondary', disabled: actionDisabled, onClick: () => perform( 'edit-metadata' ) }, __( 'Edit Metadata', 'ehrman-blog-discovery' ) ) : null,
					needsReview && ! generating ? el( Button, { variant: 'primary', disabled: actionDisabled || ! canApprove, onClick: approve }, pendingRevision ? __( 'Approve Changes', 'ehrman-blog-discovery' ) : __( 'Approve and Add to Search', 'ehrman-blog-discovery' ) ) : null,
					canReanalyze ? el( Button, {
						variant: 'secondary',
						disabled: actionDisabled,
						onClick: function () { perform( 'reanalyze', { revision: draft.revision } ); },
					}, __( 'Regenerate Metadata', 'ehrman-blog-discovery' ) ) : null,
					pendingRevision && ! draft.analysisActive ? el( Button, { variant: 'secondary', disabled: !! busyAction, onClick: () => perform( 'cancel-revision', { revision: draft.revision } ) }, __( 'Cancel Changes', 'ehrman-blog-discovery' ) ) : null,
					canRetry ? el( Button, {
						variant: 'secondary',
						disabled: actionDisabled,
						onClick: function () { perform( 'retry-embedding' ); },
					}, 'retry-embedding' === busyAction ? __( 'Retrying...', 'ehrman-blog-discovery' ) : __( 'Retry Vector', 'ehrman-blog-discovery' ) ) : null,
					error || changedReview ? el( Button, { variant: 'secondary', disabled: !! busyAction, onClick: reload }, __( 'Reload Metadata', 'ehrman-blog-discovery' ) ) : null
				),
				draft && draft.previousVersionUrl ? el( Button, { variant: 'link', href: draft.previousVersionUrl }, __( 'Previous approved version', 'ehrman-blog-discovery' ) ) : null,
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
			el( SelectControl, {
				label: __( 'Metadata mode', 'ehrman-blog-discovery' ), value: mode,
				disabled: !! busyAction || editor.isSaving || editor.postStatus === 'publish' || !! ( record && record.hasPublished ),
				options: [
					{ label: wp.i18n.sprintf( __( 'Use site default (%s)', 'ehrman-blog-discovery' ), readableStatus( config.defaultMode || 'manual' ) ), value: 'inherit' },
					{ label: __( 'Manual', 'ehrman-blog-discovery' ), value: 'manual' },
					{ label: __( 'Automatic', 'ehrman-blog-discovery' ), value: 'automatic' },
				],
				onChange: value => wp.data.dispatch( 'core/editor' ).editPost( { meta: { ...editor.meta, [ modeKey ]: value } } ),
			} ),
			content
		);
	}

	registerPlugin( 'ehrman-search-metadata', {
		render: SearchMetadataPanel,
	} );
}( window.wp, window.EhrmanPostIngestion ) );
