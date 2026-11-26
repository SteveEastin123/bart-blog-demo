<?php
/**
 * Creation and relationship editing for one organization item.
 *
 * @package EhrmanBlogDiscovery
 */

use EhrmanBlogDiscovery\Template;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$view          = $ehrman_blog_discovery_context;
$labels        = $view['labels'];
$url           = $view['url'];
$item          = $view['item'];
$item_id       = $view['item_id'];
$values        = $view['values'];
$subject_areas = $view['subject_areas'];
$category_tab  = $view['category_tab'];
$edit_label    = $view['edit_label'];
$list_url      = $url();
?>
<h2><?php echo $view['creating'] ? esc_html( $labels['add'] ) : esc_html( $edit_label ); ?></h2>
<form method="post" action="<?php echo esc_url( $url( $view['creating'] ? array( 'action' => 'new' ) : array( 'edit' => $item_id ) ) ); ?>" class="ehrman-organization__form" data-ebd-topic-form>
	<?php wp_nonce_field( 'ehrman_' . $view['scope'] . '_organization' ); ?>
	<input type="hidden" name="organization_action" value="<?php echo $view['creating'] ? 'create' : ( $category_tab ? 'links' : 'categories' ); ?>">
	<input type="hidden" name="<?php echo esc_attr( $view['scope'] . '_id' ); ?>" value="<?php echo esc_attr( (string) $item_id ); ?>">
	<input type="hidden" name="revision" value="<?php echo esc_attr( $values['revision'] ); ?>">
	<?php if ( $view['creating'] ) : ?>
		<p><label for="ebd-topic-name"><?php echo esc_html( $labels['name'] ); ?></label><input id="ebd-topic-name" name="name" type="text" maxlength="191" required value="<?php echo esc_attr( $values['name'] ); ?>"></p>
		<?php
		$description_context = array(
			'labels'           => $labels,
			'description'      => $values['description'],
			'topic_safeguards' => false,
		);
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Dynamic values are escaped in the trusted template.
		echo Template::render( 'admin/organization-description.php', $description_context );
		?>
	<?php else : ?>
		<dl class="ehrman-organization__definition">
			<dt><?php echo esc_html( $labels['singular'] ); ?></dt><dd><?php echo esc_html( $item['name'] ); ?></dd>
			<dt><?php esc_html_e( 'Description', 'ehrman-blog-discovery' ); ?></dt><dd><?php echo esc_html( $item['description'] ); ?></dd>
		</dl>
		<p><a class="button" href="
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
									"><?php esc_html_e( 'Edit Description', 'ehrman-blog-discovery' ); ?></a></p>
	<?php endif; ?>
	<?php
	$groups = $category_tab ? array(
		array(
			'kind'     => 'subject-area',
			'name'     => 'subject_area_ids',
			'required' => false,
			'options'  => $view['area_options'],
			'selected' => $values['subject_area_ids'],
		),
		array(
			'kind'     => 'topic',
			'name'     => 'topic_ids',
			'options'  => $view['topic_options'],
			'selected' => $values['topic_ids'],
		),
	) : array(
		array(
			'kind'     => 'category',
			'name'     => 'category_ids',
			'required' => ! $subject_areas,
			'options'  => $view['categories'],
			'selected' => $values['category_ids'],
		),
	);
	foreach ( $groups as $group ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Dynamic values are escaped in the trusted template.
		echo Template::render( 'admin/organization-links.php', $group );
	}
	?>
	<?php if ( $category_tab && ! empty( $item['protected_topics'] ) ) : ?>
		<p><?php esc_html_e( 'Protected topics', 'ehrman-blog-discovery' ); ?>: <?php echo esc_html( implode( ', ', array_column( $item['protected_topics'], 'name' ) ) ); ?></p>
	<?php endif; ?>
	<p class="submit ehrman-organization__actions">
		<button type="submit" class="button button-primary"><?php echo $view['creating'] ? esc_html( $labels['create'] ) : ( $category_tab ? esc_html__( 'Save Relationships', 'ehrman-blog-discovery' ) : esc_html__( 'Save Category Links', 'ehrman-blog-discovery' ) ); ?></button>
		<a href="<?php echo esc_url( $list_url ); ?>" class="button"><?php echo esc_html( $labels['back'] ); ?></a>
	</p>
</form>
<?php
if ( null !== $item ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted template escapes every value.
	echo Template::render( 'admin/organization-delete.php', $view );
}
?>
