# Imatge de HeimdEye: FrankenPHP (PHP 8.5) amb ssh i Ansible. La mateixa imatge fa de web,
# de worker de la cua i de scheduler (vegeu entrypoint.sh i compose.yaml).

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts

FROM dunglas/frankenphp:1-php8.5-trixie

RUN install-php-extensions pdo_pgsql intl zip pcntl bcmath opcache \
    && apt-get update \
    && apt-get install -y --no-install-recommends openssh-client ansible-core postgresql-client \
    && rm -rf /var/lib/apt/lists/*

# Usuari sense privilegis (sense la capability de FrankenPHP per obrir ports baixos).
RUN useradd --create-home --uid 1000 heimdeye \
    && setcap -r /usr/local/bin/frankenphp \
    && mkdir -p /data/caddy /config/caddy \
    && chown -R heimdeye:heimdeye /data/caddy /config/caddy

WORKDIR /app
COPY --from=vendor --chown=heimdeye:heimdeye /app /app
COPY docker/entrypoint.sh /usr/local/bin/heimdeye-entrypoint

USER heimdeye
RUN ansible-galaxy collection install ansible.windows \
    && php artisan package:discover --ansi \
    && php artisan filament:assets

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

EXPOSE 8080
ENTRYPOINT ["heimdeye-entrypoint"]
CMD ["web"]
