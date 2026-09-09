#!/bin/sh
set -eu

mkdir -p storage/app storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

php artisan config:cache

if [ ! -e public/storage ]; then
    php artisan storage:link
fi

exec "$@"
