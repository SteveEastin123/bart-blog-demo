<?php get_header(); ?>
<main id="main-content" class="ehrman-main ehrman-page-shell">
    <?php while (have_posts()) : the_post(); ?>
        <article <?php post_class('ehrman-content-page'); ?>>
            <h1 class="ehrman-page-title"><?php the_title(); ?></h1>
            <div class="ehrman-page-body">
                <?php the_content(); ?>
            </div>
        </article>
    <?php endwhile; ?>
</main>
<?php get_footer(); ?>
