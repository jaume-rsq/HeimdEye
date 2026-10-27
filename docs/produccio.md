# HeimdEye en producció

Com posar HeimdEye en marxa en un servidor propi, amb desplegament continu des de GitHub, i
publicar-lo amb Cloudflare. Tot el que cal per a la màquina és a `deploy/`, sense cap secret.
La part de Cloudflare (túnel, login amb Google) és a [cloudflare.md](cloudflare.md).

Una alternativa més senzilla és el Docker Compose (`docker/`, vegeu el README), però aquesta guia
fa servir paquets del sistema i systemd, que és com corre la instància de referència.

## On posar-lo

HeimdEye entra als equips dels alumnes amb una clau SSH i hi executa codi. La màquina on corre és
la més delicada de l'aula.

- **Una VM pròpia** (o compartida només amb serveis de confiança, com un Zabbix). No la de cap alumne.
- **Ha d'arribar a les xarxes dels alumnes** (per encaminament, vegeu el pla d'adreces a l'ajuda) i
  a Internet per a GitHub i Cloudflare.
- **Ningú hi ha d'entrar des de fora** excepte per Cloudflare: cap port obert al router.
- **Recursos:** 2 vCPU, 4 GB de RAM i 20 GB de disc per a una o dues classes.
- **Còpies:** la BBDD es copia a cada desplegament; afegeix-hi una còpia de la VM (instantània o
  backup del Proxmox) cada setmana.

## Les peces

| Peça | Com |
| --- | --- |
| Sistema | Ubuntu Server amb PHP 8.5 (php-fpm), Apache i PostgreSQL 18 |
| Web | Apache en un port propi (8080) → un pool de php-fpm amb l'usuari `heimdeye` |
| Workers | `heimdeye-queue@{1..4}`: usuari `heimdeye`, sistema de fitxers de només lectura menys `storage` i `/var/lib/heimdeye`, `/tmp` privat, 1 GB de memòria |
| Ronda nocturna | `heimdeye-schedule.timer` (`schedule:run` cada minut; els checks a les 02:00) |
| Desplegament | `heimdeye-deploy.timer`: cada 5 minuts, `deploy/deploy.sh` |
| Secrets | `/var/www/heimdeye/shared/.env` (plantilla: `deploy/env.production.example`) i la clau SSH a `/var/lib/heimdeye/.ssh/` |
| Publicació | Túnel de Cloudflare + Access amb Google ([cloudflare.md](cloudflare.md)) |

## Com funciona el CI/CD

```
PR ──▶ GitHub Actions (tests-sqlite, tests-pgsql) ──▶ merge a main ──▶ CI a main en verd
                                                                          │
                     servidor: heimdeye-deploy.timer (cada 5 min) ◀───────┘
                     mira main i el CI per l'API pública de GitHub, i desplega
```

- **CI** (`.github/workflows/ci.yml`): a cada PR i a cada push a `main`, els tests amb SQLite i
  amb PostgreSQL. Hi ha errors que només surten a Postgres, per això hi ha els dos.
- **Protecció de `main`**: a GitHub, **Settings** → **Rules** → **Rulesets**, una regla per a la
  branca per defecte que demani PR i els checks `tests-sqlite` i `tests-pgsql` en verd. Així no
  arriba a producció res que no hagi passat els tests.
- **Desplegament (pull)**: el servidor no rep connexions de GitHub; és ell qui pregunta. Cada
  5 minuts, `deploy.sh` mira el darrer commit de `main` (`git ls-remote`) i el resultat dels checks
  a l'API pública de GitHub. Si són verds i és un commit nou, el desplega. No cal cap secret ni
  cap runner de GitHub a la xarxa de l'aula.

El desplegament no es fa si hi ha feines a la cua o falten menys de 20 minuts per a la ronda
nocturna. Cada versió va a `releases/<data>-<sha>`:

1. `git clone` del commit, amb `.env` i `storage` enllaçats a `shared/`.
2. `composer install --no-dev`.
3. Còpia de la BBDD amb `pg_dump` a `/var/www/heimdeye/backups/` (se'n guarden 10).
4. `migrate --force`, `optimize` i `filament:optimize`.
5. `current` passa a apuntar al release nou, es recarrega php-fpm i `queue:restart`.
6. Si `/up` no respon, torna al release anterior.

Si falla, el commit queda apuntat a `failed-sha` i no es torna a provar fins que n'hi hagi un de
nou (o amb `FORCE=1`).

**Un fork propi.** Si fas servir el teu fork, digues-li a `deploy.sh` quin repositori ha de
mirar: `sudo systemctl edit heimdeye-deploy` i afegeix `Environment=HEIMDEYE_REPO=usuari/HeimdEye`.
El repositori ha de ser públic (l'API i el `git clone` no porten credencials). Altres variables:
`HEIMDEYE_BRANCH`, `HEIMDEYE_REQUIRED_CHECKS`, `HEIMDEYE_HEALTH_URL`, `HEIMDEYE_PHP_FPM`.

## Instal·lació (una vegada)

### 0. Requisits

```bash
sudo apt install php8.5-fpm php8.5-{pgsql,mbstring,xml,curl,zip,intl,bcmath} apache2 postgresql composer
```

(o el PostgreSQL que ja tingueu en una altra màquina). Abans, adapta a la teva xarxa
`deploy/apache/heimdeye.conf`: `ServerName` i el `Require ip` amb l'adreça del `cloudflared`.

### 1. Preparar la màquina

```bash
git clone https://github.com/jaume-rsq/HeimdEye.git /tmp/HeimdEye
sudo /tmp/HeimdEye/deploy/install.sh
```

`install.sh` instal·la la resta de paquets (ansible-core, python3-winrm, jq...), crea l'usuari
`heimdeye`, les carpetes, el pool de php-fpm, el lloc d'Apache, el sudoers (només
`systemctl reload php8.5-fpm`) i les unitats de systemd. Es pot tornar a executar.

### 2. Base de dades, secrets i claus

```bash
# BBDD (contrasenya amb lletres i xifres: openssl rand -hex 24)
sudo -u postgres psql -c "create user heimdeye password 'CONTRASENYA'" -c "create database heimdeye owner heimdeye"
# APP_KEY
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
# .env: APP_KEY, APP_URL, DB_PASSWORD, CF_ACCESS_*, HEIMDEYE_TRUSTED_PROXIES, HEIMDEYE_ADMIN_*
sudo -u heimdeye nano /var/www/heimdeye/shared/.env
# Clau SSH amb què HeimdEye entra als equips, i la llista d'alumnes (mai al repositori)
sudo install -o heimdeye -g heimdeye -m 600 id_ed25519 /var/lib/heimdeye/.ssh/id_ed25519
sudo install -o heimdeye -g heimdeye -m 644 id_ed25519.pub /var/lib/heimdeye/.ssh/id_ed25519.pub
sudo install -o heimdeye -g heimdeye -m 600 students.csv /var/lib/heimdeye/students.csv
```

Fes una clau SSH nova per a HeimdEye (`ssh-keygen -t ed25519 -C heimdeye`), no la teva personal:
si cal revocar-la, només afecta HeimdEye.

### 3. Primer desplegament i serveis

```bash
sudo -u heimdeye -H env SKIP_CI=1 /tmp/HeimdEye/deploy/deploy.sh
cd /var/www/heimdeye/current && sudo -u heimdeye php artisan db:seed --force
sudo systemctl enable --now heimdeye-queue@{1..4} heimdeye-schedule.timer heimdeye-deploy.timer
curl -s localhost:8080/up        # ha de respondre
```

### 4. Publicar-lo

Segueix [cloudflare.md](cloudflare.md): túnel, Google, aplicació d'Access i les variables
`CF_ACCESS_*` del `.env`. Després, `php artisan config:cache` (o espera el proper desplegament).

## Operació

```bash
journalctl -u heimdeye-deploy -n 50                                  # què ha fet el desplegament
sudo systemctl start heimdeye-deploy                                 # desplegar ara
sudo -u heimdeye -H /var/www/heimdeye/current/deploy/rollback.sh     # tornar al release anterior
journalctl -u 'heimdeye-queue@*' -f                                  # workers
```

`rollback.sh` no desfà migracions: per això convé que siguin compatibles amb la versió anterior.
Si cal, la còpia es restaura amb `pg_restore --clean -d heimdeye <fitxer>`.

## Recomanacions

- **Tallafoc:** el port de l'app només des del `cloudflared` i la mateixa màquina. Si la VM no té
  tallafoc actiu, el `Require ip` d'Apache ho fa.
- **Accés:** política d'Access per correus o domini, mai *Everyone* (vegeu els nivells a
  [cloudflare.md](cloudflare.md#nivells-de-protecció)). Pocs administradors.
- **Secrets:** el `.env`, la clau SSH i `students.csv` només al servidor. El repositori és públic.
- **Clau als equips:** l'usuari de HeimdEye als equips dels alumnes, amb `sudo` només si cal.
- **Actualitzacions:** `apt upgrade` regular; el desplegament ja manté l'app al dia.
