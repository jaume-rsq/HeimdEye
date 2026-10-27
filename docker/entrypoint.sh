#!/bin/sh
# Arrencada del contenidor: espera la BBDD i fa el paper que se li demana.
#   web        migra (i la primera vegada, si HEIMDEYE_SEED=true, omple la BBDD) i serveix l'app al :8080
#   worker     processa la cua dels checks i les accions
#   scheduler  ronda nocturna i tasques periòdiques
# Qualsevol altra cosa s'executa tal qual (p. ex. "php artisan tinker").
set -e

until pg_isready -q -h "${DB_HOST:-db}" -p "${DB_PORT:-5432}" -U "${DB_USERNAME:-heimdeye}"; do
    echo "Esperant la base de dades..."
    sleep 2
done

php artisan config:cache >/dev/null
php artisan route:cache >/dev/null
php artisan view:cache >/dev/null

case "${1:-web}" in
    web)
        php artisan migrate --force
        if [ "${HEIMDEYE_SEED:-false}" = "true" ] && [ "$(PGPASSWORD="$DB_PASSWORD" psql -h "${DB_HOST:-db}" -p "${DB_PORT:-5432}" -U "$DB_USERNAME" -d "$DB_DATABASE" -tAc 'select count(*) from usuaris')" = "0" ]; then
            php artisan db:seed --force
        fi
        exec frankenphp php-server --root public --listen :8080
        ;;
    worker)
        exec php artisan queue:work --queue=checks,default --sleep=3 --tries=1 --timeout=3700
        ;;
    scheduler)
        exec php artisan schedule:work
        ;;
    *)
        exec "$@"
        ;;
esac
