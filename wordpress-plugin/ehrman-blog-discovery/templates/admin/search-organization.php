<?php
/**
 * Topics & Categories directories and relationship forms.
 *
 * @package EhrmanBlogDiscovery
 */

use EhrmanBlogDiscovery\Database;
use EhrmanBlogDiscovery\Topic_Organization_Page;
use EhrmanBlogDiscovery\Topic_Organization_Service;
use EhrmanBlogDiscovery\Template;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$view          = $ehrman_blog_discovery_context;
$topic         = $view['topic'];
$values        = $view['values'];
$is_form       = $view['creating'] || null !== $topic;
$topic_id      = Database::integer( $topic['id'] ?? null );
$subject_areas = $view['subject_areas'];
$category_tab  = $view['category_tab'];
$url           = static fn( array $args = array() ): string => Topic_Organization_Page::url( array_merge( $view['url_args'], $args ) );
$list_url      = $url();
$labels        = $subject_areas ? array(
	'singular'     => __( 'Subject Area', 'ehrman-blog-discovery' ),
	'name'         => __( 'Subject area name', 'ehrman-blog-discovery' ),
	'add'          => __( 'Add Subject Area', 'ehrman-blog-discovery' ),
	'create'       => __( 'Create Subject Area', 'ehrman-blog-discovery' ),
	'created'      => __( 'Subject area created.', 'ehrman-blog-discovery' ),
	'back'         => __( 'Back to Subject Areas', 'ehrman-blog-discovery' ),
	'search'       => __( 'Search Subject Areas', 'ehrman-blog-discovery' ),
	'browse'       => __( 'Browse All Subject Areas', 'ehrman-blog-discovery' ),
	'matching'     => __( 'Matching subject areas', 'ehrman-blog-discovery' ),
	/* translators: %d: Number of matching subject areas. */
	'match_count'  => __( 'Matching subject areas: %d', 'ehrman-blog-discovery' ),
	'empty'        => __( 'No subject areas found.', 'ehrman-blog-discovery' ),
	/* translators: %s: Number of matching subject areas. */
	'count'        => __( '%s subject areas', 'ehrman-blog-discovery' ),
	'pages'        => __( 'Subject-area pages', 'ehrman-blog-discovery' ),
	'guidance'     => __( 'Describe the broad connection between the selected categories, rather than listing them. Aim for one sentence (about 20-40 words); use neutral wording and avoid dates and counts.', 'ehrman-blog-discovery' ),
	'example_name' => __( 'Manuscripts, Canon, and Bible Translations', 'ehrman-blog-discovery' ),
	'example'      => __( 'Covers how biblical texts were copied, collected as scripture, translated, and interpreted, including the manuscript evidence and methods scholars use to reconstruct their wording.', 'ehrman-blog-discovery' ),
) : array(
	'singular'     => __( 'Topic', 'ehrman-blog-discovery' ),
	'name'         => __( 'Topic name', 'ehrman-blog-discovery' ),
	'add'          => __( 'Add Topic', 'ehrman-blog-discovery' ),
	'create'       => __( 'Create Topic', 'ehrman-blog-discovery' ),
	'created'      => __( 'Topic created.', 'ehrman-blog-discovery' ),
	'back'         => __( 'Back to Topics', 'ehrman-blog-discovery' ),
	'search'       => __( 'Search Topics', 'ehrman-blog-discovery' ),
	'browse'       => __( 'Browse All Topics', 'ehrman-blog-discovery' ),
	'matching'     => __( 'Matching topics', 'ehrman-blog-discovery' ),
	/* translators: %d: Number of matching topics. */
	'match_count'  => __( 'Matching topics: %d', 'ehrman-blog-discovery' ),
	'empty'        => __( 'No topics found.', 'ehrman-blog-discovery' ),
	/* translators: %s: Number of matching topics. */
	'count'        => __( '%s topics', 'ehrman-blog-discovery' ),
	'pages'        => __( 'Topic pages', 'ehrman-blog-discovery' ),
	'guidance'     => __( 'Define the topic\'s scope and key distinctions from related topics. Aim for 1-2 sentences (about 25-50 words); use neutral wording and avoid post-specific details, dates, and counts. Topic descriptions also guide AI topic selection.', 'ehrman-blog-discovery' ),
	'example_name' => __( 'Composite Pauline Letters', 'ehrman-blog-discovery' ),
	'example'      => __( 'Covers scholarly arguments that a surviving Pauline epistle combines two or more originally separate letters, including proposed divisions, editorial assembly, and evidence from changes in subject, tone, or circumstances.', 'ehrman-blog-discovery' ),
);
if ( $category_tab ) {
	$labels = array(
		'singular'     => __( 'Category', 'ehrman-blog-discovery' ),
		'name'         => __( 'Category name', 'ehrman-blog-discovery' ),
		'add'          => __( 'Add Category', 'ehrman-blog-discovery' ),
		'create'       => __( 'Create Category', 'ehrman-blog-discovery' ),
		'created'      => __( 'Category created.', 'ehrman-blog-discovery' ),
		'back'         => __( 'Back to Categories', 'ehrman-blog-discovery' ),
		'search'       => __( 'Search Categories', 'ehrman-blog-discovery' ),
		'browse'       => __( 'Browse All Categories', 'ehrman-blog-discovery' ),
		'matching'     => __( 'Matching categories', 'ehrman-blog-discovery' ),
		/* translators: %d: Number of matching categories. */
		'match_count'  => __( 'Matching categories: %d', 'ehrman-blog-discovery' ),
		'empty'        => __( 'No categories found.', 'ehrman-blog-discovery' ),
		/* translators: %s: Number of matching categories. */
		'count'        => __( '%s categories', 'ehrman-blog-discovery' ),
		'pages'        => __( 'Category pages', 'ehrman-blog-discovery' ),
		'guidance'     => __( 'Describe what connects the topics in this category and distinguish it from related categories. Aim for one or two clear sentences; avoid post-specific details, dates, and counts.', 'ehrman-blog-discovery' ),
		'example_name' => __( 'Textual Criticism', 'ehrman-blog-discovery' ),
		'example'      => __( 'Covers how scholars compare textual variants and evaluate evidence to reconstruct earlier forms of biblical texts, including scribal changes and methods for assessing competing readings.', 'ehrman-blog-discovery' ),
	);
}
$edit_label = $category_tab ? __( 'Edit Relationships', 'ehrman-blog-discovery' ) : __( 'Edit Category Links', 'ehrman-blog-discovery' );
?>
<div class="wrap ehrman-organization">
	<h1><?php esc_html_e( 'Topics & Categories', 'ehrman-blog-discovery' ); ?></h1>
	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted navigation template.
	echo Template::render( 'admin/organization-tabs.php', array( 'tab' => $view['tab'] ) );
	?>
	<?php if ( '' !== $view['error'] ) : ?>
		<div class="notice notice-error" role="alert"><p><?php echo esc_html( $view['error'] ); ?></p></div>
	<?php elseif ( 'description' === $view['saved'] ) : ?>
		<div class="notice notice-success" role="status"><p><?php esc_html_e( 'Description saved.', 'ehrman-blog-discovery' ); ?></p></div>
	<?php elseif ( in_array( $view['saved'], array( 'created', 'categories', 'links' ), true ) ) : ?>
		<div class="notice notice-success" role="status"><p><?php echo 'created' === $view['saved'] ? esc_html( $labels['created'] ) : ( $category_tab ? esc_html__( 'Relationships saved.', 'ehrman-blog-discovery' ) : esc_html__( 'Category links saved.', 'ehrman-blog-discovery' ) ); ?></p></div>
	<?php endif; ?>
	<?php if ( $view['editing_description'] ) : ?>
		<h2><?php esc_html_e( 'Edit Description', 'ehrman-blog-discovery' ); ?></h2>
		<form method="post" action="
		<?php
		echo esc_url(
			$url(
				array(
					'edit'        => $topic_id,
					'description' => 'edit',
				)
			)
		);
		?>
									" class="ehrman-organization__form" data-ebd-description-form>
			<?php wp_nonce_field( 'ehrman_' . $view['scope'] . '_organization' ); ?>
			<input type="hidden" name="organization_action" value="description">
			<input type="hidden" name="<?php echo esc_attr( $view['scope'] . '_id' ); ?>" value="<?php echo esc_attr( (string) $topic_id ); ?>">
			<input type="hidden" name="revision" value="<?php echo esc_attr( $values['revision'] ); ?>">
			<dl class="ehrman-organization__definition"><dt><?php echo esc_html( $labels['singular'] ); ?></dt><dd><?php echo esc_html( $topic['name'] ); ?></dd></dl>
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
				<a href="<?php echo esc_url( $url( array( 'edit' => $topic_id ) ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'ehrman-blog-discovery' ); ?></a>
			</p>
		</form>
	<?php elseif ( $is_form ) : ?>
		<h2><?php echo $view['creating'] ? esc_html( $labels['add'] ) : esc_html( $edit_label ); ?></h2>
		<form method="post" action="<?php echo esc_url( $url( $view['creating'] ? array( 'action' => 'new' ) : array( 'edit' => $topic_id ) ) ); ?>" class="ehrman-organization__form" data-ebd-topic-form>
			<?php wp_nonce_field( 'ehrman_' . $view['scope'] . '_organization' ); ?>
			<input type="hidden" name="organization_action" value="<?php echo $view['creating'] ? 'create' : ( $category_tab ? 'links' : 'categories' ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $view['scope'] . '_id' ); ?>" value="<?php echo esc_attr( (string) $topic_id ); ?>">
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
					<dt><?php echo esc_html( $labels['singular'] ); ?></dt><dd><?php echo esc_html( $topic['name'] ); ?></dd>
					<dt><?php esc_html_e( 'Description', 'ehrman-blog-discovery' ); ?></dt><dd><?php echo esc_html( $topic['description'] ); ?></dd>
				</dl>
				<p><a class="button" href="
				<?php
				echo esc_url(
					$url(
						array(
							'edit'        => $topic_id,
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
					'options'  => $view['categories'],
					'selected' => $values['category_ids'],
				),
			);
			foreach ( $groups as $group ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Dynamic values are escaped in the trusted template.
				echo Template::render( 'admin/organization-links.php', $group );
			}
			?>
			<?php if ( $category_tab && ! empty( $topic['protected_topics'] ) ) : ?>
				<p><?php esc_html_e( 'Protected topics', 'ehrman-blog-discovery' ); ?>: <?php echo esc_html( implode( ', ', array_column( $topic['protected_topics'], 'name' ) ) ); ?></p>
			<?php endif; ?>
			<p class="submit ehrman-organization__actions">
				<button type="submit" class="button button-primary"><?php echo $view['creating'] ? esc_html( $labels['create'] ) : ( $category_tab ? esc_html__( 'Save Relationships', 'ehrman-blog-discovery' ) : esc_html__( 'Save Category Links', 'ehrman-blog-discovery' ) ); ?></button>
				<a href="<?php echo esc_url( $list_url ); ?>" class="button"><?php echo esc_html( $labels['back'] ); ?></a>
			</p>
		</form>
	<?php else : ?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="ehrman-organization__search" role="search" data-ebd-topic-search data-match-count="<?php echo esc_attr( $labels['match_count'] ); ?>" data-empty="<?php echo esc_attr( $labels['empty'] ); ?>">
			<input type="hidden" name="page" value="ehrman-search-organization">
			<input type="hidden" name="tab" value="<?php echo esc_attr( $view['tab'] ); ?>">
			<label for="ebd-find-topic"><?php echo esc_html( $labels['search'] ); ?></label>
			<div class="ehrman-organization__search-controls">
				<input id="ebd-find-topic" name="s" type="search" autocomplete="off" aria-controls="ebd-topic-matches" value="<?php echo esc_attr( $view['query'] ); ?>">
				<button type="submit" class="button"><?php echo esc_html( $labels['search'] ); ?></button>
				<a class="button" href="<?php echo esc_url( $url( array( 'view' => 'all' ) ) ); ?>"><?php echo esc_html( $labels['browse'] ); ?></a>
				<a class="button button-primary" href="<?php echo esc_url( $url( array( 'action' => 'new' ) ) ); ?>"><?php echo esc_html( $labels['add'] ); ?></a>
				<?php if ( $view['show_list'] ) : ?>
					<a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Clear Search', 'ehrman-blog-discovery' ); ?></a>
				<?php endif; ?>
			</div>
			<div class="ehrman-organization__matches" data-ebd-topic-results hidden>
				<p role="status" aria-live="polite" data-ebd-topic-status></p>
				<ul id="ebd-topic-matches" aria-label="<?php echo esc_attr( $labels['matching'] ); ?>"></ul>
				<a data-ebd-topic-more hidden><?php esc_html_e( 'View All Matches', 'ehrman-blog-discovery' ); ?></a>
			</div>
			<template data-ebd-topic-directory>
				<?php foreach ( $view['directory'] as $row ) : ?>
					<li><a href="<?php echo esc_url( $url( array( 'edit' => Database::integer( $row['id'] ) ) ) ); ?>">
						<strong data-ebd-topic-name><?php echo esc_html( $row['name'] ); ?></strong>
						<span><?php echo esc_html( implode( ', ', array_column( $row[ $category_tab ? 'subject_areas' : 'categories' ], 'name' ) ) ); ?></span>
						<?php
						if ( $category_tab ) :
							?>
							<span><?php /* translators: %d: Number of linked topics. */ echo esc_html( sprintf( __( '%d topics', 'ehrman-blog-discovery' ), count( $row['topics'] ) + count( $row['protected_topics'] ) ) ); ?></span><?php endif; ?>
					</a></li>
				<?php endforeach; ?>
			</template>
		</form>
		<?php if ( $view['show_list'] ) : ?>
		<p><?php echo esc_html( sprintf( $labels['count'], number_format_i18n( $view['count'] ) ) ); ?></p>
		<table class="widefat striped ehrman-organization__topics<?php echo $category_tab ? ' ehrman-organization__categories' : ''; ?>">
			<thead><tr>
				<?php if ( $category_tab ) : ?>
					<th scope="col"><?php esc_html_e( 'Category', 'ehrman-blog-discovery' ); ?></th><th scope="col"><?php esc_html_e( 'Subject Areas', 'ehrman-blog-discovery' ); ?></th><th scope="col"><?php esc_html_e( 'Topics', 'ehrman-blog-discovery' ); ?></th>
				<?php else : ?>
					<th scope="col"><?php echo esc_html( $labels['singular'] ); ?></th><th scope="col"><?php esc_html_e( 'Categories', 'ehrman-blog-discovery' ); ?></th><th scope="col"><?php esc_html_e( 'Posts', 'ehrman-blog-discovery' ); ?></th><th scope="col"><?php esc_html_e( 'Action', 'ehrman-blog-discovery' ); ?></th>
				<?php endif; ?>
			</tr></thead>
			<tbody>
				<?php foreach ( $view['topics'] as $row ) : ?>
					<tr>
						<?php if ( $category_tab ) : ?>
							<?php
							$category_context = array(
								'category' => $row,
								'url'      => $url( array( 'edit' => Database::integer( $row['id'] ) ) ),
							);
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Dynamic values are escaped in the trusted template.
							echo Template::render( 'admin/organization-category-row.php', $category_context );
							?>
						<?php else : ?>
						<td><?php echo esc_html( $row['name'] ); ?></td>
						<td><?php echo esc_html( implode( ', ', array_column( $row['categories'], 'name' ) ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( Database::integer( $row['post_count'] ) ) ); ?></td>
						<td>
							<?php if ( 'topics' !== $view['tab'] || Topic_Organization_Service::editable( $row ) ) : ?>
							<a href="<?php echo esc_url( $url( array( 'edit' => Database::integer( $row['id'] ) ) ) ); ?>"><?php echo esc_html( $edit_label ); ?></a>
						<?php else : ?>
							<?php esc_html_e( 'Protected', 'ehrman-blog-discovery' ); ?>
						<?php endif; ?>
						</td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
				<?php if ( empty( $view['topics'] ) ) : ?>
					<tr><td colspan="<?php echo $category_tab ? '3' : '4'; ?>"><?php echo esc_html( $labels['empty'] ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		<nav class="ehrman-organization__pagination" aria-label="<?php echo esc_attr( $labels['pages'] ); ?>">
			<?php
			if ( $view['page_number'] > 1 ) :
				?>
				<a class="button" href="
				<?php
				echo esc_url(
					$url(
						array(
							's'       => $view['query'],
							'view'    => $view['browse_all'] ? 'all' : '',
							'pagenum' => $view['page_number'] - 1,
						)
					)
				);
				?>
				"><?php esc_html_e( 'Previous', 'ehrman-blog-discovery' ); ?></a><?php endif; ?>
			<span><?php /* translators: 1: Current page, 2: Total pages. */ echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'ehrman-blog-discovery' ), $view['page_number'], $view['total_pages'] ) ); ?></span>
			<?php
			if ( $view['page_number'] < $view['total_pages'] ) :
				?>
				<a class="button" href="
				<?php
				echo esc_url(
					$url(
						array(
							's'       => $view['query'],
							'view'    => $view['browse_all'] ? 'all' : '',
							'pagenum' => $view['page_number'] + 1,
						)
					)
				);
				?>
				"><?php esc_html_e( 'Next', 'ehrman-blog-discovery' ); ?></a><?php endif; ?>
		</nav>
		<?php endif; ?>
	<?php endif; ?>
</div>
