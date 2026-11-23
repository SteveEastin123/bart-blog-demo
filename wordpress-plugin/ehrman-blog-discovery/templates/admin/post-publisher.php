<?php
/**
 * Native publisher metadata review container.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<div id="ehrman-post-metadata-root" class="ehrman-ingestion-editor"></div>
<noscript><p><a href="<?php echo esc_url( Post_Ingestion_Page::page_url() ); ?>"><?php esc_html_e( 'Open Post Metadata', 'ehrman-blog-discovery' ); ?></a></p></noscript>
