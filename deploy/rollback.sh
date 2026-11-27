#!/usr/bin/env bash
# Torna al release anterior (o al que es passi): sudo -u heimdeye deploy/rollback.sh [carpeta]
# No desfà migracions. Per desfer-les, restaura la còpia de la BBDD feta abans de desplegar:
#   sudo -u heimdeye deploy/rollback.sh --bd [fitxer.dump]   (per defecte, la més recent)
# --bd REEMPLAÇA la BBDD amb la còpia (es perden les dades noves des d'aleshores), i després torna al release anterior perquè el codi i l'esquema quadrin.
set -euo pipefail

BASE=${HEIMDEYE_BASE:-/var/www/heimdeye}
PHP_FPM=${HEIMDEYE_PHP_FPM:-php8.5-fpm}

env_value() {
    local value
    value=$(grep -E "^$1=" "$BASE/shared/.env" | tail -n 1 | cut -d= -f2- || true)
    if [[ $value =~ ^\"(.*)\"$ || $value =~ ^\'(.*)\'$ ]]; then
        value=${BASH_REMATCH[1]}
    fi
    printf '%s' "$value"
}

restore_db=""
if [[ ${1:-} == --bd ]]; then
    restore_db=1
    shift
    dump=${1:-$(ls -1t "$BASE"/backups/*.dump | head -1)}
    [[ -f $dump ]] || { echo "No trobo la còpia $dump"; exit 1; }
    echo "Es restaurarà la BBDD des de $(basename "$dump") (les dades posteriors es perden)."
    read -r -p "Escriu 'si' per continuar: " answer
    [[ $answer == si ]] || { echo "Cancel·lat"; exit 1; }
    PGPASSWORD=$(env_value DB_PASSWORD) pg_restore -w --clean --if-exists --no-owner \
        -h "$(env_value DB_HOST)" -p "$(env_value DB_PORT)" -U "$(env_value DB_USERNAME)" \
        -d "$(env_value DB_DATABASE)" "$dump"
fi

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
if [[ -n $restore_db ]]; then
    echo "BBDD restaurada. Convé reiniciar els workers: sudo systemctl restart 'heimdeye-queue@*'"
fi
