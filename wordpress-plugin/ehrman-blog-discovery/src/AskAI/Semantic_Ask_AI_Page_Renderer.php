<?php
/**
 * Semantic Ask AI page rendering.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Renders the semantic Ask AI public interface. */
final class Semantic_Ask_AI_Page_Renderer {
	/**
	 * Creates the renderer with shared discovery-page markup.
	 *
	 * @param Discovery_Markup $markup Shared discovery-page markup.
	 */
	public function __construct( private Discovery_Markup $markup ) {}

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
	public function render(
		string $question,
		string $sort,
		array $status,
		bool $ai_configured,
		bool $embedding_configured,
		string $action
	): string {
		return $this->markup->shell(
			$this->question_panel( $question, $sort, $status, $ai_configured, $embedding_configured, $action )
			. '<div class="ebd-description-control">' . $this->markup->description_control( 'always', 'posts' ) . '</div>'
			. '<div id="ebd-results" class="ebd-results" data-ebd-results></div>',
			'ask-ai-2'
		);
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
	private function question_panel(
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

		return Template::render(
			'public/ask-ai-semantic-question.php',
			array(
				'id'           => $id,
				'question'     => $question,
				'action'       => $action,
				'sort_options' => $this->sort_options( $sort ),
				'message'      => $message,
				'configured'   => $configured,
			)
		);
	}

	/**
	 * Builds result-order controls.
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
}
