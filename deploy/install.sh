#!/usr/bin/env bash
# Prepara la VM per a HeimdEye. Es pot tornar a executar (idempotent).
#   git clone https://github.com/jaume-rsq/HeimdEye.git /tmp/HeimdEye
#   sudo /tmp/HeimdEye/deploy/install.sh
# No toca el tallafoc, ni la BBDD, ni el .env: ho diu al final.
set -euo pipefail

[[ $EUID -eq 0 ]] || { echo "Cal executar-ho amb sudo"; exit 1; }
SRC=$(cd "$(dirname "$0")" && pwd)
BASE=/var/www/heimdeye
HOME_DIR=/var/lib/heimdeye

echo "== Paquets"
apt-get install -y -q git unzip curl jq ansible-core python3-winrm postgresql-client

echo "== Usuari heimdeye"
id heimdeye >/dev/null 2>&1 || useradd --system --home-dir "$HOME_DIR" --create-home --shell /bin/bash heimdeye
install -d -o heimdeye -g heimdeye -m 700 "$HOME_DIR/.ssh"

echo "== Col·lecció ansible.windows"
sudo -u heimdeye -H ansible-galaxy collection install -q ansible.windows

echo "== Carpetes a $BASE"
install -d -o heimdeye -g heimdeye "$BASE" "$BASE/releases" "$BASE/backups" "$BASE/shared"
for dir in app/public framework/cache/data framework/sessions framework/views logs; do
    install -d -o heimdeye -g heimdeye "$BASE/shared/storage/$dir"
done
if [[ ! -e $BASE/shared/.env ]]; then
    install -o heimdeye -g heimdeye -m 640 "$SRC/env.production.example" "$BASE/shared/.env"
    echo "   Creat $BASE/shared/.env a partir de la plantilla: cal omplir-lo"
fi

echo "== php-fpm i Apache"
install -m 644 "$SRC/php-fpm/heimdeye.conf" /etc/php/8.5/fpm/pool.d/heimdeye.conf
install -m 644 "$SRC/apache/heimdeye.conf" /etc/apache2/sites-available/heimdeye.conf
a2enmod -q proxy_fcgi setenvif rewrite
a2ensite -q heimdeye
apache2ctl configtest
systemctl reload php8.5-fpm apache2

echo "== sudoers"
install -m 440 "$SRC/sudoers" /etc/sudoers.d/heimdeye
visudo -cf /etc/sudoers.d/heimdeye

echo "== systemd"
install -m 644 "$SRC"/systemd/heimdeye-* /etc/systemd/system/
systemctl daemon-reload

cat <<MSG

Fet. Passos que queden (són al README, secció Producció):
  1. Crear l'usuari i la BBDD heimdeye al Postgres.
  2. Omplir $BASE/shared/.env (APP_KEY, DB_PASSWORD, CF_ACCESS_*, APP_URL, admin).
  3. Copiar la clau SSH a $HOME_DIR/.ssh/id_ed25519 i la llista d'alumnes a $HOME_DIR/students.csv.
  4. Primer desplegament: sudo -u heimdeye -H env SKIP_CI=1 $SRC/deploy.sh
  5. sudo systemctl enable --now heimdeye-queue@{1..4} heimdeye-schedule.timer heimdeye-deploy.timer
  6. Tallafoc: el port 8080 només des de 192.168.20.7 (cloudflared).
MSG
