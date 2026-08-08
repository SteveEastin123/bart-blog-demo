#!/usr/bin/env bash
set -Eeuo pipefail

required=(
  WORDPRESS_DB_HOST
  WORDPRESS_DB_NAME
  WORDPRESS_DB_USER
  WORDPRESS_DB_PASSWORD
  WORDPRESS_SITE_URL
  WORDPRESS_ADMIN_USER
  WORDPRESS_ADMIN_PASSWORD
  WORDPRESS_ADMIN_EMAIL
)

for name in "${required[@]}"; do
  if [[ -z "${!name:-}" ]]; then
    echo "Missing required environment variable: ${name}" >&2
    exit 1
  fi
done

cd /var/www/html

for attempt in $(seq 1 60); do
  # Keep the PHP snippet literal; database values are read through getenv().
  # shellcheck disable=SC2016
  if php -r '
    $parts = explode(":", getenv("WORDPRESS_DB_HOST"), 2);
    $host = $parts[0];
    $port = isset($parts[1]) ? (int) $parts[1] : 3306;
    $connection = @new mysqli(
        $host,
        getenv("WORDPRESS_DB_USER"),
        getenv("WORDPRESS_DB_PASSWORD"),
        getenv("WORDPRESS_DB_NAME"),
        $port
    );
    exit($connection->connect_errno ? 1 : 0);
  ' >/dev/null 2>&1; then
    break
  fi
  if [[ "$attempt" == "60" ]]; then
    echo "MySQL did not become available within 120 seconds." >&2
    exit 1
  fi
  sleep 2
done

if ! wp core is-installed --allow-root >/dev/null 2>&1; then
  wp core install \
    --allow-root \
    --url="$WORDPRESS_SITE_URL" \
    --title="Ehrman Blog Discovery Demo" \
    --admin_user="$WORDPRESS_ADMIN_USER" \
    --admin_password="$WORDPRESS_ADMIN_PASSWORD" \
    --admin_email="$WORDPRESS_ADMIN_EMAIL" \
    --skip-email
fi

wp option update home "$WORDPRESS_SITE_URL" --allow-root --quiet
wp option update siteurl "$WORDPRESS_SITE_URL" --allow-root --quiet
wp plugin activate ehrman-blog-discovery --allow-root --quiet
wp theme activate ehrman-discovery-demo --allow-root --quiet
wp rewrite structure '/%postname%/' --allow-root --hard --quiet
wp ehrman-discovery import --force --allow-root

echo "WordPress initialization and Ehrman discovery import completed."
