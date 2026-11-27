#!/usr/bin/env bash
# Només a PRODUCCIÓ. Escriu a stdout una còpia (pg_dump -Fc) de la BBDD de HeimdEye.
# La fa servir deploy/staging/refresca.sh per SSH:
#   ssh usuari@prod "sudo -u heimdeye /var/www/heimdeye/current/deploy/staging/dump.sh" > còpia.dump
set -euo pipefail
BASE=${HEIMDEYE_BASE:-/var/www/heimdeye}
env_value() {
    local value
    value=$(grep -E "^$1=" "$BASE/shared/.env" | tail -n 1 | cut -d= -f2- || true)
    if [[ $value =~ ^\"(.*)\"$ || $value =~ ^\'(.*)\'$ ]]; then
        value=${BASH_REMATCH[1]}
    fi
    printf '%s' "$value"
}
PGPASSWORD=$(env_value DB_PASSWORD) exec pg_dump -w -h "$(env_value DB_HOST)" -p "$(env_value DB_PORT)" \
    -U "$(env_value DB_USERNAME)" -Fc "$(env_value DB_DATABASE)"
