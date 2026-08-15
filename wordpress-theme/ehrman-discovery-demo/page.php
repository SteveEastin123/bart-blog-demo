<?php get_header(); ?>
<main id="main-content" class="ehrman-main ehrman-page-shell">
    <?php while (have_posts()) : the_post(); ?>
        <?php
        $content_class = is_page(array('browse-topics-1', 'browse-topics-2'))
            ? 'ehrman-content-page ehrman-content-page--browse'
            : 'ehrman-content-page';
        ?>
        <article <?php post_class($content_class); ?>>
            <h1 class="ehrman-page-title"><?php the_title(); ?></h1>
            <div class="ehrman-page-body">
                <?php the_content(); ?>
            </div>
        </article>
    <?php endwhile; ?>
</main>
<?php get_footer(); ?>
