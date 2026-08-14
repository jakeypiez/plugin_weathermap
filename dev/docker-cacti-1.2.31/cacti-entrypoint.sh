#!/bin/sh
set -eu

if [ -n "${TZ:-}" ]; then
	printf 'date.timezone = %s\n' "$TZ" > /usr/local/etc/php/conf.d/zz-cacti-timezone.ini
fi

mkdir -p /var/lib/cacti /var/www/html/log /var/www/html/rra
chown -R www-data:www-data /var/lib/cacti /var/www/html/log /var/www/html/rra
touch /var/www/html/log/cacti.log
chown www-data:www-data /var/www/html/log/cacti.log

if [ "${1:-}" = "bootstrap" ]; then
	exec /usr/local/bin/cacti-bootstrap
fi

exec docker-php-entrypoint "$@"
