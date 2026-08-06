<?php get_header(); ?>
<main id="main-content" class="ehrman-main ehrman-page-shell">
    <section class="ehrman-content-page">
        <h1 class="ehrman-page-title"><?php bloginfo('name'); ?></h1>
        <div class="ehrman-page-body">
            <?php if (have_posts()) : ?>
                <?php while (have_posts()) : the_post(); ?>
                    <article <?php post_class(); ?>>
                        <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                        <?php the_excerpt(); ?>
                    </article>
                <?php endwhile; ?>
            <?php else : ?>
                <p><?php esc_html_e('No content was found.', 'ehrman-discovery-demo'); ?></p>
            <?php endif; ?>
        </div>
    </section>
</main>
<?php get_footer(); ?>
