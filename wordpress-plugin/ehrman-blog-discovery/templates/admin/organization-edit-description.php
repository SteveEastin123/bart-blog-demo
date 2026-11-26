<?php
/**
 * Description-only editing with topic-specific safeguards.
 *
 * @package EhrmanBlogDiscovery
 */

use EhrmanBlogDiscovery\Template;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$view    = $ehrman_blog_discovery_context;
$labels  = $view['labels'];
$url     = $view['url'];
$item    = $view['item'];
$item_id = $view['item_id'];
$values  = $view['values'];
?>
<h2><?php esc_html_e( 'Edit Description', 'ehrman-blog-discovery' ); ?></h2>
<form method="post" action="
<?php
echo esc_url(
	$url(
		array(
			'edit'        => $item_id,
			'description' => 'edit',
		)
	)
);
?>
							" class="ehrman-organization__form" data-ebd-description-form>
	<?php wp_nonce_field( 'ehrman_' . $view['scope'] . '_organization' ); ?>
	<input type="hidden" name="organization_action" value="description">
	<input type="hidden" name="<?php echo esc_attr( $view['scope'] . '_id' ); ?>" value="<?php echo esc_attr( (string) $item_id ); ?>">
	<input type="hidden" name="revision" value="<?php echo esc_attr( $values['revision'] ); ?>">
	<dl class="ehrman-organization__definition"><dt><?php echo esc_html( $labels['singular'] ); ?></dt><dd><?php echo esc_html( $item['name'] ); ?></dd></dl>
	<?php if ( 'topics' === $view['tab'] ) : ?>
		<div class="ehrman-organization__description-guidance" id="ebd-topic-description-safeguards">
			<strong><?php esc_html_e( 'Keep the topic\'s meaning unchanged', 'ehrman-blog-discovery' ); ?></strong>
			<ul>
				<li><?php esc_html_e( 'Clarify the wording or boundaries of the existing topic. Create a new topic for a substantially different subject.', 'ehrman-blog-discovery' ); ?></li>
				<li><?php esc_html_e( 'This description guides future AI topic assignments. Saving does not reassign existing posts or regenerate their vectors.', 'ehrman-blog-discovery' ); ?></li>
				<li><?php esc_html_e( 'Changing it requires pending metadata generated with the earlier vocabulary to be regenerated before approval.', 'ehrman-blog-discovery' ); ?></li>
			</ul>
		</div>
	<?php endif; ?>
	<?php
	$description_context = array(
		'labels'           => $labels,
		'description'      => $values['description'],
		'topic_safeguards' => 'topics' === $view['tab'],
	);
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Dynamic values are escaped in the trusted template.
	echo Template::render( 'admin/organization-description.php', $description_context );
	?>
	<p class="submit ehrman-organization__actions">
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Description', 'ehrman-blog-discovery' ); ?></button>
		<a href="<?php echo esc_url( $url( array( 'edit' => $item_id ) ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'ehrman-blog-discovery' ); ?></a>
	</p>
</form>
