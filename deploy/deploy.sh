#!/usr/bin/env bash
# Desplega a /var/www/heimdeye el darrer commit de main que tingui el CI en verd.
# El llança heimdeye-deploy.timer cada 5 minuts com a usuari heimdeye; també es pot
# executar a mà: sudo -u heimdeye /var/www/heimdeye/current/deploy/deploy.sh [sha]
#
# Variables: FORCE=1 desplega encara que el sha ja hi sigui o hagi fallat abans;
#            SKIP_CI=1 no mira el CI; SKIP_GUARD=1 no mira la cua ni la ronda nocturna.
set -euo pipefail

BASE=${HEIMDEYE_BASE:-/var/www/heimdeye}
REPO=${HEIMDEYE_REPO:-jaume-rsq/HeimdEye}
BRANCH=${HEIMDEYE_BRANCH:-main}
REQUIRED_CHECKS=${HEIMDEYE_REQUIRED_CHECKS:-"tests-sqlite tests-pgsql"}
HEALTH_URL=${HEIMDEYE_HEALTH_URL:-http://127.0.0.1:8080/up}
PHP_FPM=${HEIMDEYE_PHP_FPM:-php8.5-fpm}
KEEP_RELEASES=5
KEEP_BACKUPS=10

log() { echo "[heimdeye-deploy] $*"; }

cd "$BASE"
exec 9>"$BASE/deploy.lock"
flock -n 9 || { log "Ja hi ha un desplegament en marxa"; exit 0; }

sha=${1:-$(git ls-remote "https://github.com/$REPO.git" "refs/heads/$BRANCH" | cut -f1)}
[[ $sha =~ ^[0-9a-f]{40}$ ]] || { log "No s'ha pogut obtenir el commit de $BRANCH"; exit 1; }
current_sha=$(cat "$BASE/current/REVISION" 2>/dev/null || true)

if [[ -z ${FORCE:-} ]]; then
    [[ $sha == "$current_sha" ]] && exit 0
    if [[ $sha == "$(cat "$BASE/failed-sha" 2>/dev/null || true)" ]]; then
        log "${sha:0:7} ja va fallar; no es torna a provar (FORCE=1 per forçar-ho)"
        exit 0
    fi
fi

if [[ -z ${SKIP_CI:-} ]]; then
    runs=$(curl -fsS -H 'Accept: application/vnd.github+json' \
        "https://api.github.com/repos/$REPO/commits/$sha/check-runs?per_page=100")
    for check in $REQUIRED_CHECKS; do
        conclusion=$(jq -r --arg name "$check" \
            '[.check_runs[] | select(.name == $name)] | max_by(.started_at) | .conclusion // "pendent"' <<<"$runs")
        if [[ $conclusion != success ]]; then
            log "${sha:0:7}: $check = $conclusion; s'espera"
            exit 0
        fi
    done
fi

if [[ -z ${SKIP_GUARD:-} && -e $BASE/current ]]; then
    if ! reason=$(cd "$BASE/current" && php artisan heimdeye:can-deploy 2>&1); then
        log "${sha:0:7}: ara no ($reason)"
        exit 0
    fi
fi

release="$BASE/releases/$(date +%Y%m%d-%H%M%S)-${sha:0:7}"
fail() {
    log "Ha fallat el desplegament de ${sha:0:7}: $1"
    echo "$sha" >"$BASE/failed-sha"
    exit 1
}
log "Desplegant ${sha:0:7} a $release"

git clone -q --filter=blob:none --no-checkout "https://github.com/$REPO.git" "$release" || fail "git clone"
git -C "$release" checkout -q "$sha" || fail "git checkout"
rm -rf "$release/.git" "$release/storage"
echo "$sha" >"$release/REVISION"
ln -s "$BASE/shared/.env" "$release/.env"
ln -s "$BASE/shared/storage" "$release/storage"

cd "$release"
composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader \
    || { rm -rf "$release"; fail "composer install"; }

# Còpia de la BBDD abans de migrar.
mkdir -p "$BASE/backups"
set -a
# shellcheck disable=SC1090
source <(grep -E '^DB_(HOST|PORT|DATABASE|USERNAME|PASSWORD)=' "$BASE/shared/.env")
set +a
PGPASSWORD=$DB_PASSWORD pg_dump -h "${DB_HOST:-127.0.0.1}" -p "${DB_PORT:-5432}" -U "$DB_USERNAME" -Fc "$DB_DATABASE" \
    >"$BASE/backups/$(date +%Y%m%d-%H%M%S)-abans-de-${sha:0:7}.dump" || { rm -rf "$release"; fail "pg_dump"; }
ls -1t "$BASE"/backups/*.dump | tail -n +$((KEEP_BACKUPS + 1)) | xargs -r rm -f

php artisan migrate --force || { rm -rf "$release"; fail "migrate (la còpia és a $BASE/backups)"; }
php artisan optimize
php artisan filament:optimize

previous=""
[[ -L $BASE/current ]] && previous=$(readlink -f "$BASE/current")
switch_to() {
    ln -sfn "$1" "$BASE/current.new"
    mv -T "$BASE/current.new" "$BASE/current"
    sudo -n systemctl reload "$PHP_FPM"
    (cd "$BASE/current" && php artisan queue:restart)
}
switch_to "$release"

sleep 2
if ! curl -fsS -o /dev/null "$HEALTH_URL"; then
    if [[ -n $previous ]]; then
        switch_to "$previous"
        fail "/up no respon; s'ha tornat a $(basename "$previous") (les migracions es queden fetes)"
    fi
    fail "/up no respon"
fi

rm -f "$BASE/failed-sha"
ls -1dt "$BASE"/releases/*/ | tail -n +$((KEEP_RELEASES + 1)) | xargs -r rm -rf
log "Desplegat ${sha:0:7}"
