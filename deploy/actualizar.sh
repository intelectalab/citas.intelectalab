#!/bin/bash
set -e
R=/var/www/citas
AS="sudo -u citas -H"
FECHA=$(date +%F_%H%M)

echo "== 1. Copia de la base"
mkdir -p /var/backups/citas
sudo -u postgres pg_dump -F c citas > /var/backups/citas/citas_$FECHA.dump
ls -t /var/backups/citas/*.dump | tail -n +15 | xargs -r rm -f
echo "Guardada en /var/backups/citas/citas_$FECHA.dump"

echo "== 2. Código nuevo"
cd $R
$AS git pull --ff-only

echo "== 3. API"
cd $R/apps/api
$AS php8.4 /usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction --quiet
$AS php8.4 artisan migrate --force
$AS php8.4 artisan optimize:clear >/dev/null

echo "== 4. Web"
cd $R/apps/web
$AS npm ci --no-audit --no-fund --loglevel=error
$AS npm run build
supervisorctl restart citas-web

echo "== Listo"
cd $R && $AS git log --oneline -1
