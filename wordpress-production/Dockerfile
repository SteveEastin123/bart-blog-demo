# syntax=docker/dockerfile:1

FROM wordpress:cli@sha256:2b5e9d4d3e51909dca1aaa4732e9f5e5bf0377c2114dbd8ff39f060bff202586 AS wpcli

FROM wordpress:php8.3-apache@sha256:b2d7e3153c8a96f90305a3102fb6439335237fb1a9655b617d15c5168ce2f7a3

COPY --from=wpcli /usr/local/bin/wp /usr/local/bin/wp

COPY --chown=www-data:www-data wordpress-plugin/ehrman-blog-discovery/ \
    /opt/ehrman-code/plugins/ehrman-blog-discovery/
COPY --chown=www-data:www-data wordpress-theme/ehrman-discovery-demo/ \
    /opt/ehrman-code/themes/ehrman-discovery-demo/

RUN mkdir -p /opt/ehrman-import
COPY --chown=www-data:www-data data/index/ehrman_post_search_index.json \
    data/index/ehrman_post_topics.json \
    data/index/ehrman_post_categories.json \
    data/index/ehrman_post_subject_areas.json \
    data/index/ehrman_post_subject_areas_2.json \
    /opt/ehrman-import/

COPY --chown=www-data:www-data wordpress-production/healthz.html /opt/ehrman-code/healthz
COPY wordpress-production/initialize-wordpress.sh /usr/local/bin/initialize-ehrman-wordpress
COPY wordpress-production/runtime-entrypoint.sh /usr/local/bin/ehrman-wordpress-entrypoint

RUN chmod 0755 /usr/local/bin/initialize-ehrman-wordpress /usr/local/bin/ehrman-wordpress-entrypoint \
    && a2enmod rewrite headers expires

ENTRYPOINT ["ehrman-wordpress-entrypoint"]
CMD ["apache2-foreground"]
