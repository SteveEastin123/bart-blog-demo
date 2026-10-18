<?php
/**
 * Guest search form for the local section-search pilot.
 *
 * @package EhrmanSectionPublic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section class="section-public" aria-label="<?php esc_attr_e( 'Ask AI 3 search', 'ehrman-section-public' ); ?>">
	<p class="section-public-scope"><?php esc_html_e( 'Section-search trial: 3,986 posts', 'ehrman-section-public' ); ?></p>
	<form id="section-public-form">
		<label for="section-public-question"><?php esc_html_e( 'Question or request', 'ehrman-section-public' ); ?></label>
		<textarea id="section-public-question" name="question" rows="3" maxlength="800" required></textarea>
		<div class="section-public-actions">
			<button type="submit"><?php esc_html_e( 'Find Posts', 'ehrman-section-public' ); ?></button>
			<p id="section-public-status" role="status" aria-live="polite"></p>
		</div>
	</form>
	<div id="section-public-error" role="alert" hidden></div>
	<section id="section-public-results" aria-label="<?php esc_attr_e( 'Search results', 'ehrman-section-public' ); ?>" aria-busy="false"></section>
	<noscript><p><?php esc_html_e( 'JavaScript is required to search.', 'ehrman-section-public' ); ?></p></noscript>
</section>
