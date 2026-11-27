#!/usr/bin/env bash
# Només a STAGING. Substitueix la BBDD de staging per una còpia fresca de producció i aplica
# les migracions del codi que hi hagi desplegat (és el que es vol provar).
#   sudo -u heimdeye -H /var/www/heimdeye/current/deploy/staging/refresca.sh
# Variable: HEIMDEYE_PROD_SSH = usuari@màquina de producció (per defecte, la del fitxer
# /var/lib/heimdeye/prod-ssh). L'usuari ha de poder fer "sudo -u heimdeye .../dump.sh" sense contrasenya.
set -euo pipefail

BASE=${HEIMDEYE_BASE:-/var/www/heimdeye}
PROD_SSH=${HEIMDEYE_PROD_SSH:-$(cat /var/lib/heimdeye/prod-ssh 2>/dev/null || true)}
[[ -n $PROD_SSH ]] || { echo "Falta HEIMDEYE_PROD_SSH (usuari@màquina de producció)"; exit 1; }

env_value() {
    local value
    value=$(grep -E "^$1=" "$BASE/shared/.env" | tail -n 1 | cut -d= -f2- || true)
    if [[ $value =~ ^\"(.*)\"$ || $value =~ ^\'(.*)\'$ ]]; then
        value=${BASH_REMATCH[1]}
    fi
    printf '%s' "$value"
}

# Seguretat: aquest script esborra la BBDD. Només hi corre si el .env diu que és staging.
[[ $(env_value HEIMDEYE_ENVIRONMENT) == staging ]] || { echo "Aquest .env no és de staging (HEIMDEYE_ENVIRONMENT=staging): no faig res"; exit 1; }
[[ $(env_value DB_HOST) =~ ^(127\.0\.0\.1|localhost)$ ]] || { echo "La BBDD de staging ha de ser local"; exit 1; }

dump=$(mktemp /tmp/heimdeye-prod-XXXXXX.dump)
trap 'rm -f "$dump"' EXIT
echo "Baixant la còpia de producció..."
ssh -o BatchMode=yes "$PROD_SSH" 'sudo -n -u heimdeye /var/www/heimdeye/current/deploy/staging/dump.sh' >"$dump"
[[ -s $dump ]] || { echo "La còpia és buida"; exit 1; }

echo "Restaurant a staging..."
export PGPASSWORD=$(env_value DB_PASSWORD)
pg_restore -w --clean --if-exists --no-owner -h "$(env_value DB_HOST)" -p "$(env_value DB_PORT)" \
    -U "$(env_value DB_USERNAME)" -d "$(env_value DB_DATABASE)" "$dump"

cd "$BASE/current"
php artisan migrate --force
php artisan queue:restart
echo "Staging refrescat amb les dades de producció."
