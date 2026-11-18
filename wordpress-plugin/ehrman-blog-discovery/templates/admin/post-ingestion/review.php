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

$view               = $ehrman_blog_discovery_context;
$regenerate_context = array(
	'id'        => $view['action_id'],
	'native_id' => $view['native_id'],
	'revision'  => $view['revision'],
	'label'     => __( 'Regenerate Metadata', 'ehrman-blog-discovery' ),
);
?>
<h2><?php echo esc_html__( 'Review metadata', 'ehrman-blog-discovery' ); ?></h2>
<?php if ( 'held' === $view['status'] && ! empty( $view['warnings'] ) ) : ?>
	<div class="notice notice-warning inline"><ul>
		<?php foreach ( $view['warnings'] as $warning ) : ?>
			<li><?php echo esc_html( $warning ); ?></li>
		<?php endforeach; ?>
	</ul></div>
<?php endif; ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-ebd-metadata-review data-requires-topics="<?php echo 'ready' === $view['status'] ? '1' : '0'; ?>">
	<input type="hidden" name="action" value="ehrman_ingestion_proposal"><input type="hidden" name="draft_id" value="<?php echo esc_attr( $view['id'] ); ?>">
	<input type="hidden" name="revision" value="<?php echo esc_attr( $view['revision'] ); ?>">
	<?php wp_nonce_field( 'ehrman_ingestion_proposal' ); ?>
	<table class="form-table" role="presentation">
		<tr><th scope="row"><label for="description"><?php echo esc_html__( 'Description', 'ehrman-blog-discovery' ); ?></label></th><td><textarea class="large-text" id="description" name="description" rows="2" required><?php echo esc_textarea( $view['description'] ); ?></textarea></td></tr>
		<tr><th scope="row"><label for="search_summary"><?php echo esc_html__( 'Summary', 'ehrman-blog-discovery' ); ?></label></th><td><textarea class="large-text" id="search_summary" name="search_summary" rows="5" required><?php echo esc_textarea( $view['summary'] ); ?></textarea></td></tr>
		<?php
		foreach ( array(
			'topics'   => __( 'Topics', 'ehrman-blog-discovery' ),
			'keywords' => __( 'Keywords', 'ehrman-blog-discovery' ),
		) as $field => $label ) :
			$selection_context = array(
				'kind'   => 'topics' === $field ? 'topic' : 'keyword',
				'field'  => 'removed_' . $field,
				'labels' => $view[ $field ],
			);
			?>
			<tr>
				<th scope="row"><?php echo esc_html( $label ); ?></th>
				<td>
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The trusted partial escapes its values.
					echo Template::render( 'admin/post-ingestion/selections.php', $selection_context );
					?>
				</td>
			</tr>
		<?php endforeach; ?>
	</table>
	<p class="ehrman-metadata-selections__warning" data-ebd-topic-warning role="alert" hidden><?php echo esc_html__( 'Keep at least one topic. Add an existing topic or restore a removed topic.', 'ehrman-blog-discovery' ); ?></p>
	<div class="ehrman-ingestion__actions">
		<button class="button" type="submit" name="ingestion_intent" value="save"><?php echo esc_html__( 'Save revisions', 'ehrman-blog-discovery' ); ?></button>
		<button class="button button-primary" type="submit" name="ingestion_intent" value="approve"<?php disabled( ! $view['database_authoritative'] || 'ready' !== $view['status'] ); ?>><?php echo $view['is_revision'] ? esc_html__( 'Approve Changes', 'ehrman-blog-discovery' ) : esc_html__( 'Approve and Add to Search', 'ehrman-blog-discovery' ); ?></button>
	</div>
</form>
<div class="ehrman-ingestion__actions">
	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The trusted partial escapes its display values.
	echo Template::render( 'admin/post-ingestion/reanalyze.php', $regenerate_context );
	?>
	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The trusted partial escapes its display values.
	echo Template::render( 'admin/post-ingestion/discard.php', $view );
	?>
</div>
