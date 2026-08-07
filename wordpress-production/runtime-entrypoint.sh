#!/usr/bin/env bash
set -Eeuo pipefail

document_root=/var/www/html
plugin_target="$document_root/wp-content/plugins/ehrman-blog-discovery"
theme_target="$document_root/wp-content/themes/ehrman-discovery-demo"

mkdir -p "$document_root/wp-content/plugins" "$document_root/wp-content/themes"

# Refresh image-managed code on every start. This avoids stale files when the
# official WordPress image's declared /var/www/html volume survives a recreate.
rm -rf "$plugin_target" "$theme_target" "$document_root/wp-content/ehrman-import"
cp -a /opt/ehrman-code/plugins/ehrman-blog-discovery "$plugin_target"
cp -a /opt/ehrman-code/themes/ehrman-discovery-demo "$theme_target"
install -m 0644 /opt/ehrman-code/healthz "$document_root/healthz"
chown -R www-data:www-data "$plugin_target" "$theme_target" "$document_root/healthz"

exec docker-entrypoint.sh "$@"
