<?php
/**
 * Disabled delete control or an explicit, no-JavaScript confirmation page.
 *
 * @package EhrmanBlogDiscovery
 */

use EhrmanBlogDiscovery\Admin_Menu;
use EhrmanBlogDiscovery\Database;
use EhrmanBlogDiscovery\Organization_Deletion_Settings;
use EhrmanBlogDiscovery\Topic_Organization_Page;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$view     = $ehrman_blog_discovery_context;
$deletion = $view['deletion'];
$confirm  = $view['confirming_delete'];
$reasons  = is_wp_error( $deletion ) ? array( $deletion->get_error_message() ) : $deletion['blockers'];
if ( ! Organization_Deletion_Settings::enabled() ) {
	array_unshift( $reasons, __( 'Deletion is disabled in Blog Search settings.', 'ehrman-blog-discovery' ) );
}
$record_id = Database::integer( $view['item']['id'] );
$args      = array(
	'tab'  => $view['tab'],
	'edit' => $record_id,
);
?>
<section class="ehrman-organization__deletion" aria-label="<?php esc_attr_e( 'Delete item', 'ehrman-blog-discovery' ); ?>">
	<?php if ( $confirm ) : ?>
		<h2><?php esc_html_e( 'Confirm deletion', 'ehrman-blog-discovery' ); ?></h2>
		<p><strong><?php echo esc_html( $view['item']['name'] ); ?></strong></p>
		<p><?php esc_html_e( 'This permanently deletes this item and its links below. It does not delete posts or any linked Subject Areas, Categories, or Topics. This cannot be undone here.', 'ehrman-blog-discovery' ); ?></p>
		<?php if ( ! is_wp_error( $deletion ) ) : ?>
			<?php foreach ( $deletion['groups'] as $label => $links ) : ?>
				<h3><?php echo esc_html( $label ); ?></h3>
				<?php if ( $links ) : ?>
					<ul>
						<?php foreach ( $links as $linked_item ) : ?>
							<li><?php echo esc_html( $linked_item['name'] ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p><?php esc_html_e( 'None', 'ehrman-blog-discovery' ); ?></p>
				<?php endif; ?>
			<?php endforeach; ?>
			<?php if ( $deletion['aliases'] ) : ?>
				<p><?php /* translators: %d: Number of topic aliases. */ echo esc_html( sprintf( __( 'Alternative Topic search names also removed: %d.', 'ehrman-blog-discovery' ), $deletion['aliases'] ) ); ?></p>
			<?php endif; ?>
			<?php if ( 'topics' === $view['tab'] ) : ?>
				<p><?php esc_html_e( 'Pending metadata based on the earlier Topic vocabulary will need to be regenerated before approval.', 'ehrman-blog-discovery' ); ?></p>
			<?php endif; ?>
		<?php endif; ?>
	<?php endif; ?>
	<?php if ( $reasons ) : ?>
		<ul id="ebd-deletion-reasons">
			<?php foreach ( $reasons as $reason ) : ?>
				<li><?php echo esc_html( $reason ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
	<?php if ( ! Organization_Deletion_Settings::enabled() ) : ?>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin_Menu::SETTINGS ) ); ?>"><?php esc_html_e( 'Blog Search settings', 'ehrman-blog-discovery' ); ?></a></p>
	<?php endif; ?>
	<form method="<?php echo $confirm ? 'post' : 'get'; ?>" action="<?php echo esc_url( $confirm ? Topic_Organization_Page::url( $args ) : admin_url( 'admin.php' ) ); ?>" data-ebd-delete-form>
		<?php if ( $confirm ) : ?>
			<?php wp_nonce_field( 'ehrman_' . $view['scope'] . '_organization_delete' ); ?>
			<input type="hidden" name="organization_action" value="delete">
			<input type="hidden" name="<?php echo esc_attr( $view['scope'] . '_id' ); ?>" value="<?php echo esc_attr( (string) $record_id ); ?>">
			<input type="hidden" name="revision" value="<?php echo esc_attr( is_wp_error( $deletion ) ? '' : $deletion['revision'] ); ?>">
			<input type="hidden" name="confirm_delete" value="yes">
		<?php else : ?>
			<input type="hidden" name="page" value="ehrman-search-organization">
			<input type="hidden" name="tab" value="<?php echo esc_attr( $view['tab'] ); ?>">
			<input type="hidden" name="edit" value="<?php echo esc_attr( (string) $record_id ); ?>">
			<input type="hidden" name="action" value="delete">
		<?php endif; ?>
		<button type="submit" class="button ehrman-organization__delete-button" <?php disabled( ! empty( $reasons ) ); ?> <?php echo $reasons ? 'aria-describedby="ebd-deletion-reasons"' : ''; ?>>
			<span class="dashicons dashicons-trash" aria-hidden="true"></span>
			<?php echo $confirm ? esc_html__( 'Permanently Delete', 'ehrman-blog-discovery' ) : esc_html__( 'Delete', 'ehrman-blog-discovery' ); ?>
		</button>
		<?php if ( $confirm ) : ?>
			<a class="button" href="<?php echo esc_url( Topic_Organization_Page::url( $args ) ); ?>"><?php esc_html_e( 'Cancel', 'ehrman-blog-discovery' ); ?></a>
		<?php endif; ?>
	</form>
</section>
