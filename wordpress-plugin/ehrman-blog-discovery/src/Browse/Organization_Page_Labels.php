<?php
/**
 * Presentation labels and description guidance for Manage Topics tabs.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keeps tab-specific wording out of the organization templates. */
final class Organization_Page_Labels {
	/**
	 * Returns labels for an editing tab; routing normalizes the tab beforehand.
	 *
	 * @param string $tab Validated editing tab.
	 * @return array<string,string> Translatable labels.
	 */
	public static function for_tab( string $tab ): array {
		return match ( $tab ) {
			'subject-areas' => array(
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
				'guidance'     => __( 'Describe the broad scope of the subject area, rather than listing categories. Aim for one sentence (about 20-40 words); use neutral wording and avoid dates and counts.', 'ehrman-blog-discovery' ),
				'example_name' => __( 'Manuscripts, Canon, and Bible Translations', 'ehrman-blog-discovery' ),
				'example'      => __( 'Covers how biblical texts were copied, collected as scripture, translated, and interpreted, including the manuscript evidence and methods scholars use to reconstruct their wording.', 'ehrman-blog-discovery' ),
			),
			'categories' => array(
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
			),
			default => array(
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
			),
		};
	}
}
