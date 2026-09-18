<?php
/**
 * Topic-and-keyword Ask AI page rendering.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Renders the topic-and-keyword Ask AI public interface. */
final class Taxonomy_Ask_AI_Page_Renderer {
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
	public function render(
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
	 * Builds the Ask AI 1 form and interpretation review.
	 *
	 * @param string $question      Reader question.
	 * @param string $sort          Selected result order.
	 * @param bool   $ai_configured Whether question interpretation is configured.
	 * @param string $action        Form action URL.
	 * @return string Question form markup.
	 */
	private function question_panel( string $question, string $sort, bool $ai_configured, string $action ): string {
		return Template::render(
			'public/ask-ai-question.php',
			array(
				'id'            => $this->markup->next_control_id( 'ebd-question' ),
				'question'      => $question,
				'action'        => $action,
				'sort_options'  => $this->sort_options( $sort ),
				'ai_configured' => $ai_configured,
			)
		);
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
