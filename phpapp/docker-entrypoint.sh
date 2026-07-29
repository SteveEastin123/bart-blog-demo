#!/bin/sh
set -eu

port="${PORT:-10000}"
sed -i "s/^Listen .*/Listen ${port}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[^>]*>/<VirtualHost *:${port}>/" /etc/apache2/sites-enabled/000-default.conf

exec apache2-foreground
