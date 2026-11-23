<?php
/** Native publisher preferences, scope and first-publication ordering; no API calls. */

use EhrmanBlogDiscovery\Post_Publisher as Publisher;
use EhrmanBlogDiscovery\Post_Ingestion_Publishing_Settings as Publishing;
use EhrmanBlogDiscovery\Post_Ingestion_Automation as Automation;

( static function () use ( $assert, $assert_same ): void {
	$old_user = get_current_user_id(); $old_post = $_POST;
	$old_screen = $GLOBALS['current_screen'] ?? null;
	$old_global_post = $GLOBALS['post'] ?? null;
	$old_mode = get_option( Publishing::OPTION, false );
	$ids = array(); $users = array();
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	$assert( ! empty( $admins ), 'Publisher tests require an administrator.' );
	$observed = array();
	$observe = static function ( $id ) use ( &$observed ): void { $observed[$id] = Publishing::mode( $id ); };
	// Observe the preference at automation's boundary without scheduling paid jobs.
	remove_action( 'wp_after_insert_post', array( Automation::class, 'after_save' ), 20 );
	add_action( 'wp_after_insert_post', $observe, 19 );
	$create = static function ( array $values = array() ) use ( &$ids ): int {
		$id = wp_insert_post( array_merge( array( 'post_type' => 'post', 'post_status' => 'auto-draft', 'post_title' => 'Publisher regression', 'post_content' => '<p>Publisher regression content.</p>' ), $values ), true );
		if ( is_wp_error( $id ) ) throw new RuntimeException( $id->get_error_message() );
		$ids[] = $id; return $id;
	};
	$submit = static function ( int $id, string $mode ): void {
		$_POST = array( 'ehrman_publisher_nonce' => wp_create_nonce( 'ehrman-publisher-' . $id ), 'ehrman_metadata_mode' => $mode );
	};
	try {
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/meta-boxes.php';
		wp_set_current_user( (int) $admins[0] ); update_option( Publishing::OPTION, 'automatic' ); $_POST = array();
		$id = $create();
		$GLOBALS['current_screen'] = WP_Screen::get( 'post' );
		$GLOBALS['post'] = get_post( $id );
		$settings = array( 'media_buttons' => true, 'teeny' => false );
		$assert_same( array( 'media_buttons' => false, 'teeny' => false ), Publisher::editor_settings( $settings, 'content' ), 'Publisher did not hide only media insertion.' );
		$assert_same( $settings, Publisher::editor_settings( $settings, 'another-editor' ), 'Other editors lost their media controls.' );
		$GLOBALS['current_screen'] = WP_Screen::get( 'dashboard' );
		$assert_same( $settings, Publisher::editor_settings( $settings, 'content' ), 'Other screens lost their media controls.' );
		$GLOBALS['current_screen'] = WP_Screen::get( 'post' );
		ob_start(); Publisher::author_field( get_post( $id ) ); $author_html = ob_get_clean();
		$author_tags = new WP_HTML_Tag_Processor( $author_html );
		$assert( $author_tags->next_tag( 'SELECT' ) && 'post_author_override' === $author_tags->get_attribute( 'name' ), 'Publisher did not render the native author selector.' );
		$assert( Publisher::uses_form( get_post( $id ) ), 'Add Post does not use the publisher.' );
		$assert_same( false, Publisher::block_editor( true, get_post( $id ) ), 'New posts still open the block editor.' );
		$assert_same( 'Title', Publisher::title_label( 'Add title', get_post( $id ) ), 'Form title label is not persistent wording.' );
		$assert_same( 10, has_action( 'post_submitbox_misc_actions', array( Publisher::class, 'mode_controls' ) ), 'Metadata mode is not hooked into the Publish panel.' );
		ob_start(); do_action( 'post_submitbox_misc_actions', get_post( $id ) ); $html = ob_get_clean();
		$assert( str_contains( $html, '(site default)' ) && 1 === preg_match( '/value="automatic"\s+checked/', $html ), 'Automatic site default is not shown and selected.' );
		$assert( ! str_contains( $html, '<select' ), 'Publisher metadata mode must use radios.' );
		$assert_same( 1, substr_count( $html, 'name="ehrman_publisher_nonce"' ), 'Publish panel must contain one publisher nonce.' );
		ob_start(); Publisher::metadata_box(); $review_html = ob_get_clean();
		$assert( str_contains( $review_html, 'id="ehrman-post-metadata-root"' ), 'Metadata review mount point was removed.' );
		$assert( ! str_contains( $review_html, 'ehrman_metadata_mode' ) && ! str_contains( $review_html, 'ehrman_publisher_nonce' ), 'Metadata review duplicates publishing controls.' );
		$submit( $id, 'manual' );
		wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
		$assert_same( 'manual', $observed[$id], 'Manual override was not saved before automatic publication handling.' );
		$assert_same( 'automatic', Publishing::default_mode(), 'Per-post override changed the site default.' );
		ob_start(); Publisher::mode_controls( get_post( $id ) ); $html = ob_get_clean();
		$assert( 1 === preg_match( '/<fieldset[^>]+disabled/', $html ) && 1 === preg_match( '/value="manual"\s+checked/', $html ), 'Published mode is not displayed as a locked saved choice.' );
		$assert( Publisher::uses_form( get_post( $id ) ), 'Published form post does not retain its editor.' );
		$submit( $id, 'automatic' ); wp_update_post( array( 'ID' => $id, 'post_title' => 'Changed publisher fixture' ) );
		$assert_same( 'manual', Publishing::mode( $id ), 'A published mode was changed by a crafted form submission.' );
		update_post_meta( $id, Automation::PUBLISHED_META, '1' ); wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
		$assert_same( 'manual', Publishing::mode( $id ), 'Unpublishing unlocked the metadata choice.' );
		$_POST = array(); update_option( Publishing::OPTION, 'manual' );
		$scheduled = $create(); $submit( $scheduled, 'automatic' );
		wp_update_post( array( 'ID' => $scheduled, 'post_status' => 'future', 'edit_date' => true, 'post_date' => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ), 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ) );
		$assert_same( 'future', get_post_status( $scheduled ), 'Scheduled fixture was not scheduled.' );
		$assert_same( 'automatic', $observed[$scheduled], 'Scheduled posts lost their override.' );
		$assert( ! Publisher::mode_locked( get_post( $scheduled ) ), 'Scheduling locked the choice before publication.' );
		$_POST = array(); $draft = $create( array( 'post_status' => 'draft' ) );
		$assert( ! Publisher::uses_form( get_post( $draft ) ), 'Existing unmarked posts were switched to a different editor.' );
		$assert_same( 'Existing title', Publisher::title_label( 'Existing title', get_post( $draft ) ), 'Existing editor title label was changed.' );
		$GLOBALS['post'] = get_post( $draft );
		$assert_same( $settings, Publisher::editor_settings( $settings, 'content' ), 'Existing unmarked posts lost their media controls.' );
		ob_start(); Publisher::author_field( get_post( $draft ) ); $author_html = ob_get_clean();
		$assert_same( '', $author_html, 'Publisher author field appeared outside its scope.' );
		ob_start(); Publisher::mode_controls( get_post( $draft ) ); $html = ob_get_clean();
		$assert_same( '', $html, 'Unmarked posts received publisher mode controls.' );
		$blocks = $create( array( 'post_content' => '<!-- wp:paragraph --><p>Keep blocks.</p><!-- /wp:paragraph -->' ) );
		update_post_meta( $blocks, Publisher::FORM_META, '1' );
		$assert_same( true, Publisher::block_editor( true, get_post( $blocks ) ), 'Block content was sent to the form editor.' );
		$GLOBALS['post'] = get_post( $blocks );
		$assert_same( $settings, Publisher::editor_settings( $settings, 'content' ), 'Block posts lost their media controls.' );
		ob_start(); Publisher::mode_controls( get_post( $blocks ) ); $html = ob_get_clean();
		$assert_same( '', $html, 'Block posts received publisher mode controls.' );
		$page = $create( array( 'post_type' => 'page' ) );
		$assert_same( true, Publisher::block_editor( true, get_post( $page ) ), 'Pages were affected.' );
		$assert_same( 'Page title', Publisher::title_label( 'Page title', get_post( $page ) ), 'Page title label was changed.' );
		$GLOBALS['post'] = get_post( $page );
		$assert_same( $settings, Publisher::editor_settings( $settings, 'content' ), 'Pages lost their media controls.' );
		ob_start(); Publisher::mode_controls( get_post( $page ) ); $html = ob_get_clean();
		$assert_same( '', $html, 'Pages received publisher mode controls.' );
		$fresh = $create();
		foreach ( array( '', 'invalid', wp_create_nonce( 'ehrman-publisher-' . $id ) ) as $nonce ) {
			$_POST = array( 'ehrman_publisher_nonce' => $nonce, 'ehrman_metadata_mode' => 'automatic' );
			wp_update_post( array( 'ID' => $fresh, 'post_status' => 'draft' ) );
			$assert( ! metadata_exists( 'post', $fresh, Publishing::META ), 'Invalid nonce saved a publishing preference.' );
		}
		$submit( $fresh, 'invalid' ); wp_update_post( array( 'ID' => $fresh, 'post_title' => 'Invalid preference' ) );
		$assert( ! metadata_exists( 'post', $fresh, Publishing::META ), 'Invalid mode was accepted.' );
		$editor = wp_insert_user( array( 'user_login' => 'publisher-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
		$assert( ! is_wp_error( $editor ), 'Cannot create permission fixture.' ); $users[] = $editor;
		wp_set_current_user( $editor ); $submit( $fresh, 'automatic' );
		wp_update_post( array( 'ID' => $fresh, 'post_title' => 'Editor permission fixture' ) );
		$assert( ! metadata_exists( 'post', $fresh, Publishing::META ), 'Non-administrator changed metadata mode.' );
		$assert( ! Publisher::uses_form( get_post( $fresh ) ), 'Administrator-only workflow exposed to editors.' );
		ob_start(); Publisher::mode_controls( get_post( $id ) ); $html = ob_get_clean();
		$assert_same( '', $html, 'Non-administrator received publisher mode controls.' );
	} finally {
		$_POST = array(); wp_set_current_user( (int) $admins[0] );
		foreach ( $ids as $id ) { wp_clear_scheduled_hook( 'publish_future_post', array( $id ) ); wp_delete_post( $id, true ); }
		if ( ! function_exists( 'wp_delete_user' ) ) require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $users as $user ) wp_delete_user( $user );
		if ( false === $old_mode ) delete_option( Publishing::OPTION ); else update_option( Publishing::OPTION, $old_mode );
		remove_action( 'wp_after_insert_post', $observe, 19 );
		add_action( 'wp_after_insert_post', array( Automation::class, 'after_save' ), 20, 4 );
		$_POST = $old_post; wp_set_current_user( $old_user );
		$GLOBALS['current_screen'] = $old_screen;
		$GLOBALS['post'] = $old_global_post;
	}
} )();
echo "Native publisher: author field, scoped media controls, editor scope, mode defaults/overrides, publish ordering, scheduled posts, locked choices and permissions: OK\n";
