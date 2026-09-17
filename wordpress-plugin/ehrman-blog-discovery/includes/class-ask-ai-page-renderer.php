<?php
/**
 * Ask AI page rendering.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Renders the Ask AI 1 and Ask AI 2 public interfaces. */
final class Ask_AI_Page_Renderer {
	/**
	 * Creates the renderer with its presentation collaborators.
	 *
	 * @param Discovery_Markup     $markup      Shared discovery-page markup.
	 * @param Search_Page_Renderer $search_page Shared search controls and results.
	 */
	public function __construct(
		private Discovery_Markup $markup,
		private Search_Page_Renderer $search_page
	) {}

	/**
	 * Renders Ask AI 1 with interpreted terms and optional results.
	 *
	 * @param string                                                                                                                $question      Reader question.
	 * @param string                                                                                                                $request_id    Ask AI request identifier.
	 * @param array<int,string>                                                                                                     $terms         Interpreted search terms.
	 * @param array<int,string>                                                                                                     $term_modes    Search modes aligned with terms.
	 * @param array{posts:list<array<string,mixed>>,terms:list<string>,sort:string,count:int,page:int,per_page:int,total_pages:int} $result        Search result payload.
	 * @param bool                                                                                                                  $auto_refine   Whether automatic refinement is pending.
	 * @param bool                                                                                                                  $ai_configured Whether question interpretation is configured.
	 * @param string                                                                                                                $action        Ask AI 1 page URL.
	 * @return string Ask AI 1 markup.
	 */
	public function render_ask_ai_1(
		string $question,
		string $request_id,
		array $terms,
		array $term_modes,
		array $result,
		bool $auto_refine,
		bool $ai_configured,
		string $action
	): string {
		$has_terms = ! empty( $terms );
		return $this->markup->shell(
			$this->question_panel( $question, $result['sort'], $ai_configured, $action )
			. ( $has_terms
				? $this->search_page->search_panel(
					$terms,
					$term_modes,
					$result['sort'],
					true,
					$action,
					'',
					'',
					null,
					'',
					'<input type="hidden" name="ebd_question" value="' . esc_attr( $question ) . '"><input type="hidden" name="ebd_ai_request" value="' . esc_attr( $request_id ) . '">',
					true
				)
				: '<div class="ebd-description-control">' . $this->markup->description_control( 'always', 'posts' ) . '</div>' )
			. '<div id="ebd-results" class="ebd-results" data-ebd-results>'
			. ( $auto_refine
				? $this->automatic_refinement_markup()
				: ( $has_terms ? $this->search_page->results_markup( $result, '', $question, $request_id ) : '' ) )
			. '</div>',
			'ask-question'
		);
	}

	/**
	 * Renders Ask AI 2 semantic retrieval.
	 *
	 * @param string              $question             Reader question.
	 * @param string              $sort                 Selected result order.
	 * @param array<string,mixed> $status               Semantic index status.
	 * @param bool                $ai_configured        Whether AI refinement is configured.
	 * @param bool                $embedding_configured Whether query embeddings are configured.
	 * @param string              $action               Ask AI 2 page URL.
	 * @return string Ask AI 2 markup.
	 */
	public function render_ask_ai_2(
		string $question,
		string $sort,
		array $status,
		bool $ai_configured,
		bool $embedding_configured,
		string $action
	): string {
		return $this->markup->shell(
			$this->semantic_question_panel( $question, $sort, $status, $ai_configured, $embedding_configured, $action )
			. '<div class="ebd-description-control">' . $this->markup->description_control( 'always', 'posts' ) . '</div>'
			. '<div id="ebd-results" class="ebd-results" data-ebd-results></div>',
			'ask-ai-2'
		);
	}

	/**
	 * Builds the Ask AI 1 form and interpretation review.
	 *
	 * @param string $question      Reader question.
	 * @param string $sort          Selected result order.
	 * @param bool   $ai_configured Whether question interpretation is configured.
	 * @param string $action        Form action URL.
	 * @return string Question form markup.
	 */
	private function question_panel( string $question, string $sort, bool $ai_configured, string $action ): string {
		$id                 = $this->markup->next_control_id( 'ebd-question' );
		$sort_options       = $this->sort_options( $sort );
		$configured_message = $ai_configured
			? ''
			: '<p class="ebd-question-configuration">' . esc_html__( 'Local AI credentials must be configured before questions can be interpreted.', 'ehrman-blog-discovery' ) . '</p>';
		$review_markup      = '<section class="ebd-question-review" data-ebd-question-review hidden><h3>'
			. esc_html__( 'Review the interpreted search', 'ehrman-blog-discovery' ) . '</h3><p>'
			. esc_html__( 'These are the topics and keywords selected from your question. Remove any that do not reflect what you intended.', 'ehrman-blog-discovery' )
			. '</p><ul data-ebd-question-terms></ul><div class="ebd-sort-row"><span>'
			. esc_html__( 'Sort by', 'ehrman-blog-discovery' ) . '</span>' . $sort_options
			. '</div><div class="ebd-question-actions"><button type="submit" class="ebd-question-search" data-ebd-question-search disabled>'
			. esc_html__( 'Search posts', 'ehrman-blog-discovery' ) . '</button></div></section>';

		return '<form class="ebd-question-panel" action="' . esc_url( $action )
			. '" method="get" data-ebd-question-form><input type="hidden" name="ebd_ai_request" value="" data-ebd-ai-request><div id="' . esc_attr( $id )
			. '-controls" data-ebd-question-expanded><label for="' . esc_attr( $id ) . '"><strong>'
			. esc_html__( 'What would you like to explore?', 'ehrman-blog-discovery' ) . '</strong></label><p class="ebd-question-help">'
			. esc_html__( 'Ask a question or describe what you want to find. AI will find related posts on Bart\'s blog for you to review.', 'ehrman-blog-discovery' )
			. '</p><textarea id="' . esc_attr( $id ) . '" name="ebd_question" rows="3" maxlength="800" required '
			. 'placeholder="' . esc_attr__( 'Example: How do the teachings of Paul differ from those of Jesus?', 'ehrman-blog-discovery' )
			. '" data-ebd-question-input>' . esc_textarea( $question ) . '</textarea><div class="ebd-question-actions">'
			. '<button type="button" class="ebd-question-interpret" data-ebd-question-interpret'
			. ( $ai_configured ? '' : ' disabled' ) . '>' . esc_html__( 'Submit', 'ehrman-blog-discovery' )
			. '</button><button type="button" class="ebd-question-clear" data-ebd-question-clear>'
			. esc_html__( 'Clear', 'ehrman-blog-discovery' ) . '</button></div>' . $configured_message
			. '<p class="ebd-question-status" data-ebd-question-status aria-live="polite"></p>'
			. $review_markup . '</div></form>';
	}

	/**
	 * Builds the Ask AI 2 question form.
	 *
	 * @param string              $question             Reader question.
	 * @param string              $sort                 Selected result order.
	 * @param array<string,mixed> $status               Semantic index status.
	 * @param bool                $ai_configured        Whether AI refinement is configured.
	 * @param bool                $embedding_configured Whether query embeddings are configured.
	 * @param string              $action               Form action URL.
	 * @return string Question form markup.
	 */
	private function semantic_question_panel(
		string $question,
		string $sort,
		array $status,
		bool $ai_configured,
		bool $embedding_configured,
		string $action
	): string {
		$id         = $this->markup->next_control_id( 'ebd-semantic-question' );
		$configured = $ai_configured && $embedding_configured && $status['ready'];
		$message    = '';
		if ( ! $ai_configured || ! $embedding_configured ) {
			$message = __( 'Local AI credentials must be configured before semantic search can run.', 'ehrman-blog-discovery' );
		} elseif ( ! $status['ready'] ) {
			$message = __( 'The semantic post index must be built before Ask AI 2 can run.', 'ehrman-blog-discovery' );
		}

		return '<form class="ebd-question-panel ebd-semantic-panel" action="' . esc_url( $action )
			. '" method="get" data-ebd-semantic-form' . ( '' !== trim( $question ) ? ' data-ebd-auto-run="true"' : '' )
			. '><input type="hidden" name="ebd_ai_request" value="" data-ebd-ai-request><label for="' . esc_attr( $id ) . '"><strong>'
			. esc_html__( 'What would you like to explore?', 'ehrman-blog-discovery' ) . '</strong></label><p class="ebd-question-help">'
			. esc_html__( 'Ask a question or describe what you want to find. AI will find related posts on Bart\'s blog for you to review.', 'ehrman-blog-discovery' )
			. '</p><textarea id="' . esc_attr( $id ) . '" name="ebd_question" rows="3" maxlength="800" required placeholder="'
			. esc_attr__( 'Example: How does Luke change Mark?', 'ehrman-blog-discovery' )
			. '" data-ebd-semantic-question>' . esc_textarea( $question ) . '</textarea><div class="ebd-question-actions">'
			. '<button type="submit" class="ebd-question-interpret" data-ebd-semantic-submit' . ( $configured ? '' : ' disabled' ) . '>'
			. esc_html__( 'Submit', 'ehrman-blog-discovery' ) . '</button><button type="button" class="ebd-question-clear" data-ebd-semantic-clear>'
			. esc_html__( 'Clear', 'ehrman-blog-discovery' ) . '</button></div>'
			. ( '' === $message ? '' : '<p class="ebd-question-configuration">' . esc_html( $message ) . '</p>' )
			. '<p class="ebd-question-status" data-ebd-semantic-status aria-live="polite"></p><div class="ebd-sort-row ebd-semantic-sort"><span>'
			. esc_html__( 'Sort by', 'ehrman-blog-discovery' ) . '</span>' . $this->sort_options( $sort ) . '</div></form>';
	}

	/**
	 * Builds shared result-order controls.
	 *
	 * @param string $sort Selected result order.
	 * @return string Sort-control markup.
	 */
	private function sort_options( string $sort ): string {
		$options = array();
		foreach ( array(
			'ranked' => 'Best match',
			'newest' => 'Newest first',
			'oldest' => 'Oldest first',
		) as $value => $label ) {
			$options[] = '<label class="ebd-sort-choice"><input type="radio" name="ebd_sort" value="' . esc_attr( $value )
				. '"' . checked( $sort, $value, false ) . '><span>' . esc_html( $label ) . '</span></label>';
		}
		return implode( '', $options );
	}

	/** Builds the pending state shown while Ask AI refines broader matches. */
	private function automatic_refinement_markup(): string {
		return '<div class="ebd-ai-refine is-loading" data-ebd-ai-refine data-ebd-auto-refine aria-live="polite">'
			. '<span data-ebd-refine-status>'
			. esc_html__( 'AI is reviewing the matching post titles and summaries...', 'ehrman-blog-discovery' )
			. '</span></div>';
	}
}
