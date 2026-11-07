<?php
/** Seed disposable editorial data to prove initialization never overwrites it. */
use EhrmanBlogDiscovery\Database;
use EhrmanBlogDiscovery\Post_Ingestion_Settings;
use EhrmanBlogDiscovery\Semantic_Index_Service;

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) || ! WP_HTTP_BLOCK_EXTERNAL
	|| ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON || ! Post_Ingestion_Settings::database_is_authoritative() ) {
	throw new RuntimeException( 'This fixture requires an isolated, HTTP-blocked, database-managed rehearsal.' );
}
$wpdb = Database::client();
$tables = Database::tables();
$source = $wpdb->get_row( "SELECT p.* FROM {$tables['external_posts']} p JOIN {$tables['post_embeddings']} e ON e.source_wp_id=p.source_wp_id ORDER BY p.id LIMIT 1", ARRAY_A );
if ( ! $source ) { throw new RuntimeException( 'Missing persistence fixture source.' ); }
$vector = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE source_wp_id=%d', $tables['post_embeddings'], $source['source_wp_id'] ), ARRAY_A );
$native = wp_insert_post( array(
	'import_id' => 1000 + (int) $wpdb->get_var( "SELECT MAX(source_wp_id) FROM {$tables['external_posts']}" ),
	'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Disposable Render rehearsal post',
	'post_content' => 'A local-only persistence fixture. No AI calls are made.',
), true );
if ( is_wp_error( $native ) ) { throw new RuntimeException( $native->get_error_message() ); }
$row = $source;
unset( $row['id'] );
$row['source_wp_id'] = $native;
$row['wordpress_post_id'] = $native;
$row['title'] = 'Disposable Render rehearsal post';
$row['url'] = get_permalink( $native );
$row['url_hash'] = hash( 'sha256', $row['url'], true );
if ( ! $wpdb->insert( $tables['external_posts'], $row ) ) { throw new RuntimeException( 'Could not seed editorial metadata.' ); }
$post_id = (int) $wpdb->insert_id;
foreach ( array( 'post_topics' => 'topic_id', 'post_keywords' => 'keyword_id', 'post_search_terms' => 'label,normalized,kind,weight' ) as $key => $columns ) {
	if ( false === $wpdb->query( $wpdb->prepare( "INSERT INTO %i (post_id,{$columns}) SELECT %d,{$columns} FROM %i WHERE post_id=%d", $tables[$key], $post_id, $tables[$key], $source['id'] ) ) ) {
		throw new RuntimeException( 'Could not seed editorial assignments.' );
	}
}
$vector['source_wp_id'] = $native;
$vector['content_hash'] = Semantic_Index_Service::content_hash( $row );
if ( ! $wpdb->insert( $tables['post_embeddings'], $vector ) ) { throw new RuntimeException( 'Could not seed mock vector.' ); }
update_option( 'ehrman_render_rehearsal_post', $native, false );
echo "Seeded local-only editorial metadata, assignments, and a mock vector for persistence testing.\n";
