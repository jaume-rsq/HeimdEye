#!/usr/bin/env bash
# Torna al release anterior (o al que es passi): sudo -u heimdeye deploy/rollback.sh [carpeta]
# No desfà migracions: si cal, restaura la còpia de /var/www/heimdeye/backups amb pg_restore.
set -euo pipefail

BASE=${HEIMDEYE_BASE:-/var/www/heimdeye}
PHP_FPM=${HEIMDEYE_PHP_FPM:-php8.5-fpm}

current=$(readlink -f "$BASE/current")
target=${1:-$(ls -1dt "$BASE"/releases/*/ | sed 's:/$::' | { grep -vx "$current" || true; } | head -1)}
[[ -d $target ]] || { echo "No hi ha cap release on tornar"; exit 1; }

ln -sfn "$target" "$BASE/current.new"
mv -T "$BASE/current.new" "$BASE/current"
sudo -n systemctl reload "$PHP_FPM"
(cd "$BASE/current" && php artisan queue:restart)
# Que el timer no hi torni a desplegar el commit dolent.
cat "$current/REVISION" >"$BASE/failed-sha"
echo "Ara és a $(basename "$target")"
