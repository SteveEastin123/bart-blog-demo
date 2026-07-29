FROM python:3.13-slim AS database-builder

WORKDIR /opt/ehrman

COPY data/index/ehrman_post_categories.json data/index/ehrman_post_categories.json
COPY data/index/ehrman_post_search_index.json data/index/ehrman_post_search_index.json
COPY data/index/ehrman_post_subject_areas.json data/index/ehrman_post_subject_areas.json
COPY data/index/ehrman_post_subject_areas_2.json data/index/ehrman_post_subject_areas_2.json
COPY data/index/ehrman_post_topics.json data/index/ehrman_post_topics.json
COPY webapp/__init__.py webapp/__init__.py
COPY webapp/import_data.py webapp/import_data.py

RUN mkdir -p webapp/data && python -m webapp.import_data

FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/app

COPY phpapp /var/www/app/phpapp
COPY data/index/ehrman_post_categories.json /var/www/app/data/index/ehrman_post_categories.json
COPY data/index/ehrman_post_search_index.json /var/www/app/data/index/ehrman_post_search_index.json
COPY data/index/ehrman_post_subject_areas.json /var/www/app/data/index/ehrman_post_subject_areas.json
COPY data/index/ehrman_post_subject_areas_2.json /var/www/app/data/index/ehrman_post_subject_areas_2.json
COPY data/index/ehrman_post_topics.json /var/www/app/data/index/ehrman_post_topics.json
COPY webapp/static /var/www/app/webapp/static
COPY --from=database-builder /opt/ehrman/webapp/data/ehrman_search.db /var/www/app/webapp/data/ehrman_search.db
COPY phpapp/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY phpapp/docker-entrypoint.sh /usr/local/bin/ehrman-entrypoint

RUN ln -s /var/www/app/webapp/static /var/www/app/phpapp/public/static \
    && sed -i 's/\r$//' /usr/local/bin/ehrman-entrypoint \
    && chmod +x /usr/local/bin/ehrman-entrypoint

EXPOSE 10000

ENTRYPOINT ["ehrman-entrypoint"]
