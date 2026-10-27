# Imatge de HeimdEye: FrankenPHP (PHP 8.5) amb ssh i Ansible. La mateixa imatge fa de web,
# de worker de la cua i de scheduler, i a la demo també de contenidor de proves (vegeu
# entrypoint.sh, compose.yaml i compose.demo.yaml).

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts

FROM dunglas/frankenphp:1-php8.5-trixie

RUN install-php-extensions pdo_pgsql intl zip pcntl bcmath opcache \
    && apt-get update \
    && apt-get install -y --no-install-recommends openssh-client openssh-server ansible-core postgresql-client \
       iputils-ping dnsutils netcat-openbsd curl \
    && rm -rf /var/lib/apt/lists/*

# Usuari sense privilegis (sense la capability de FrankenPHP per obrir ports baixos).
RUN useradd --create-home --shell /bin/bash --uid 1000 heimdeye \
    && setcap -r /usr/local/bin/frankenphp \
    && (setcap -r /usr/bin/ping 2>/dev/null || true) \
    && mkdir -p /data/caddy /config/caddy \
    && mkdir -p /keys/runner /keys/students \
    && chown -R heimdeye:heimdeye /data/caddy /config/caddy /keys

WORKDIR /app
COPY --from=vendor --chown=heimdeye:heimdeye /app /app
COPY docker/entrypoint.sh /usr/local/bin/heimdeye-entrypoint
COPY docker/sandbox/heimdeye-sandbox /usr/local/bin/heimdeye-sandbox
COPY docker/sandbox/sshd_config /etc/heimdeye/sandbox_sshd_config

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
