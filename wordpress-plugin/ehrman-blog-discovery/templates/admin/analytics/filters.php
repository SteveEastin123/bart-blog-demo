<?php
/**
 * Analytics filter form.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$filters   = $ehrman_blog_discovery_context['filters'];
$clear_url = $ehrman_blog_discovery_context['clear_url'];
?>
<form method="get" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin:18px 0">
	<input type="hidden" name="page" value="ehrman-ai-analytics">
	<input type="hidden" name="tab" value="ask-ai">
	<label><?php echo esc_html__( 'From', 'ehrman-blog-discovery' ); ?><br><input type="date" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>"></label>
	<label><?php echo esc_html__( 'To', 'ehrman-blog-discovery' ); ?><br><input type="date" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>"></label>
	<label><?php echo esc_html__( 'Question', 'ehrman-blog-discovery' ); ?><br><input type="search" name="search" value="<?php echo esc_attr( $filters['search'] ); ?>"></label>
	<label><input type="checkbox" name="zero_results" value="1" <?php checked( $filters['zero_results'], '1' ); ?>> <?php echo esc_html__( 'Zero results', 'ehrman-blog-discovery' ); ?></label>
	<button class="button button-primary" type="submit"><?php echo esc_html__( 'Apply filters', 'ehrman-blog-discovery' ); ?></button>
	<a class="button" href="<?php echo esc_url( $clear_url ); ?>"><?php echo esc_html__( 'Clear', 'ehrman-blog-discovery' ); ?></a>
</form>
