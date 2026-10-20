<?php
/**
 * Post ingestion review display.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$view = $ehrman_blog_discovery_context;
?>
<h2><?php echo esc_html__( 'Review proposal', 'ehrman-blog-discovery' ); ?></h2>
<p><?php echo esc_html__( 'Revise any field as needed. Saving remains a draft; approval is the only action that writes to the live index.', 'ehrman-blog-discovery' ); ?></p>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="ehrman_ingestion_proposal"><input type="hidden" name="draft_id" value="<?php echo esc_attr( $view['id'] ); ?>">
	<input type="hidden" name="topic_rationales_json" value="<?php echo esc_attr( $view['rationales_json'] ); ?>">
	<?php wp_nonce_field( 'ehrman_ingestion_proposal' ); ?>
	<table class="form-table" role="presentation">
		<tr><th scope="row"><label for="description"><?php echo esc_html__( 'Description', 'ehrman-blog-discovery' ); ?></label></th><td><textarea class="large-text" id="description" name="description" rows="2" required><?php echo esc_textarea( $view['description'] ); ?></textarea><p class="description"><?php echo esc_html__( 'Begin with an active, present-tense verb. Use one declarative sentence, generally 18-23 words and fewer than 30.', 'ehrman-blog-discovery' ); ?></p></td></tr>
		<tr><th scope="row"><label for="search_summary"><?php echo esc_html__( 'Search summary', 'ehrman-blog-discovery' ); ?></label></th><td><textarea class="large-text" id="search_summary" name="search_summary" rows="5" required><?php echo esc_textarea( $view['summary'] ); ?></textarea><p class="description"><?php echo esc_html__( 'Begin with an active verb and a declarative statement. Normally use three sentences and about 60-80 words.', 'ehrman-blog-discovery' ); ?></p></td></tr>
		<tr><th scope="row"><label for="topics"><?php echo esc_html__( 'Topics', 'ehrman-blog-discovery' ); ?></label></th><td><select class="regular-text" id="topics" name="topics[]" multiple size="12">
		<?php
		foreach ( $view['vocabulary_topics'] as $topic ) :
			?>
			<option value="<?php echo esc_attr( $topic['name'] ); ?>"<?php selected( in_array( $topic['name'], $view['topics'], true ) ); ?> title="<?php echo esc_attr( $topic['description'] ); ?>"><?php echo esc_html( $topic['name'] ); ?></option><?php endforeach; ?></select><p class="description"><?php echo esc_html__( 'Select only primary, sustained subjects. Hold a substantive post when no existing topic fits.', 'ehrman-blog-discovery' ); ?></p></td></tr>
		<?php if ( ! empty( $view['rationales'] ) ) : ?>
			<tr><th scope="row"><?php echo esc_html__( 'AI topic rationales', 'ehrman-blog-discovery' ); ?></th><td><ul class="ehrman-ingestion__rationales">
			<?php foreach ( $view['rationales'] as $rationale ) : ?>
				<li><strong><?php echo esc_html( $rationale['topic'] ); ?>:</strong> <?php echo esc_html( $rationale['rationale'] ); ?></li>
			<?php endforeach; ?>
			</ul><p class="description"><?php echo esc_html__( 'These explanations document the original AI proposal; revise the topic selections above whenever the evidence does not support them.', 'ehrman-blog-discovery' ); ?></p></td></tr>
		<?php endif; ?>
		<tr><th scope="row"><label for="secondary_keywords"><?php echo esc_html__( 'Existing secondary keywords', 'ehrman-blog-discovery' ); ?></label></th><td><textarea class="large-text" id="secondary_keywords" name="secondary_keywords" rows="6"><?php echo esc_textarea( $view['keywords'] ); ?></textarea><p class="description"><?php echo esc_html__( 'Use one exact existing label per line.', 'ehrman-blog-discovery' ); ?></p></td></tr>
		<tr class="ehrman-ingestion__new"><th scope="row"><label for="new_secondary_keywords"><?php echo esc_html__( 'NEW secondary keywords', 'ehrman-blog-discovery' ); ?></label></th><td><textarea class="large-text" id="new_secondary_keywords" name="new_secondary_keywords" rows="3"><?php echo esc_textarea( implode( "\n", $view['new_keywords'] ) ); ?></textarea><p class="description"><?php echo esc_html__( 'Use only when no existing exact term, variant, or reasonable synonym fits.', 'ehrman-blog-discovery' ); ?></p></td></tr>
		<tr><th scope="row"><label for="status"><?php echo esc_html__( 'Decision', 'ehrman-blog-discovery' ); ?></label></th><td><select id="status" name="status"><option value="ready"<?php selected( 'ready', $view['status'] ); ?>><?php echo esc_html__( 'Ready', 'ehrman-blog-discovery' ); ?></option><option value="held"<?php selected( 'held', $view['status'] ); ?>><?php echo esc_html__( 'Held', 'ehrman-blog-discovery' ); ?></option></select></td></tr>
		<tr><th scope="row"><label for="review_notes"><?php echo esc_html__( 'Review notes', 'ehrman-blog-discovery' ); ?></label></th><td><textarea class="large-text" id="review_notes" name="review_notes" rows="4"><?php echo esc_textarea( $view['notes'] ); ?></textarea><p class="description"><?php echo esc_html__( 'Required for ambiguity, a new keyword, or no suitable topic.', 'ehrman-blog-discovery' ); ?></p></td></tr>
		<?php
		if ( ! empty( $view['new_keywords'] ) ) :
			?>
			<tr class="ehrman-ingestion__new"><th scope="row"><?php echo esc_html__( 'New-keyword approval', 'ehrman-blog-discovery' ); ?></th><td><label><input type="checkbox" name="approve_new_keywords" value="1"> <?php echo esc_html__( 'I explicitly approve creating the NEW secondary keyword labels shown above.', 'ehrman-blog-discovery' ); ?></label></td></tr><?php endif; ?>
	</table>
	<div class="ehrman-ingestion__actions">
		<button class="button" type="submit" name="ingestion_intent" value="save"><?php echo esc_html__( 'Save revisions', 'ehrman-blog-discovery' ); ?></button>
		<button class="button button-primary" type="submit" name="ingestion_intent" value="approve"<?php disabled( ! $view['database_authoritative'] ); ?>><?php echo esc_html__( 'Approve and add to index', 'ehrman-blog-discovery' ); ?></button>
	</div>
</form>
<div class="ehrman-ingestion__actions">
	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The trusted partial escapes its display values.
	echo Template::render( 'admin/post-ingestion/reanalyze.php', array( 'id' => $view['action_id'] ) );
	?>
	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The trusted partial escapes its display values.
	echo Template::render( 'admin/post-ingestion/discard.php', array( 'id' => $view['action_id'] ) );
	?>
</div>
