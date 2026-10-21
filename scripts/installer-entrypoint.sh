#!/bin/sh
set -eu
cd /var/www/core
for directory in app config database resources routes themes; do
  mkdir -p "$directory"
  cp -a "/source/core/$directory/." "$directory/"
done
mkdir -p bootstrap/cache storage/app/media storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
cp /source/core/bootstrap/app.php /source/core/bootstrap/providers.php bootstrap/
cp /source/core/artisan /source/core/composer.json /source/core/composer.lock .
if [ ! -f .env ]; then cp /run/solqubit-install/app.env .env; fi
if [ ! -f storage/app/installed.json ]; then
  for setting in SOLQUBIT_INSTALL_DB_HOST SOLQUBIT_INSTALL_DB_NAME SOLQUBIT_INSTALL_DB_USER; do
    if ! grep -q "^$setting=" .env; then grep "^$setting=" /run/solqubit-install/app.env >> .env; fi
  done
fi
chown www-data:www-data /var/www/core .env
chown -R www-data:www-data storage bootstrap/cache
php artisan package:discover --quiet
exec apache2-foreground
