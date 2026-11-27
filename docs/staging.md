# Entorn de proves (staging)

Una segona instància de HeimdEye, separada de producció, amb una còpia de les seves dades.
Serveix per provar un PR (migracions incloses) abans de fusionar-lo a `main`.

## Com funciona

- **Mateix codi, altre lloc.** Staging és un HeimdEye instal·lat amb `deploy/install.sh`, igual que
  producció, en un contenidor LXC propi, amb el seu Postgres i la seva URL
  (`heimdeye-staging.julioantonio.cat`) darrere de Cloudflare Access només per a l'administrador.
- **Quin codi hi corre.** El timer de desplegament de staging té `HEIMDEYE_PR_LABEL=staging`: cada
  5 minuts mira si hi ha un PR obert amb l'etiqueta `staging` i, si el CI d'aquell commit és en verd,
  el desplega. Per provar un PR, hi poses l'etiqueta; quan el fusiones, producció el desplega com sempre.
  Si hi ha diversos PR amb l'etiqueta, guanya el que s'ha tocat més recentment.
- **Quines dades.** `deploy/staging/refresca.sh` substitueix la BBDD de staging per una còpia fresca de
  producció i hi aplica les migracions del codi desplegat. Convé fer-lo abans de provar un PR amb migracions.
  Staging té la **mateixa `APP_KEY`** que producció perquè els secrets copiats es puguin llegir.
- **Què executa.** No hi ha ronda nocturna ni scheduler (`heimdeye-schedule.timer` no s'activa). Només corren
  els checks que llancis a mà ("Provar", "Comprovar ara"). **Compte:** els equips són els reals dels
  alumnes; una acció que llancis des de staging també els modifica.
- **Producció** no canvia el seu flux: còpia de la BBDD abans de cada migració i tornada al codi anterior
  si `/up` falla. Nou: `rollback.sh --bd` restaura aquella còpia (vegeu *Desfer una migració dolenta*).

## Instruccions per a Claude Code (muntar-ho una vegada)

Tasca: crear el contenidor de staging i deixar-lo desplegant PR etiquetats. Executa-ho des d'un
ordinador amb SSH a l'amfitrió dels contenidors (Proxmox/Incus) i a la màquina de producció
(`192.168.20.12`). Comprova cada pas abans de passar al següent i **no toquis producció** més
enllà del pas 4.

1. **Contenidor.** LXC Ubuntu 26.04 sense privilegis amb `nesting=1` (els serveis de systemd amb
   aïllament ho necessiten), 2 vCPU, 2 GB de RAM, 20 GB, xarxa 192.168.20.0/24 amb IP fixa (proposa-la
   a Jaume si no és evident quina és lliure), arrencada automàtica. Ha de poder fer SSH als equips dels
   alumnes (la mateixa xarxa que producció).
2. **Paquets i instal·lació** (dins el contenidor):
   `apt install php8.5-fpm php8.5-{pgsql,mbstring,xml,curl,zip,intl,bcmath} apache2 postgresql composer git sudo openssh-client iputils-ping netcat-openbsd bind9-dnsutils`,
   clona el repositori a `/tmp/HeimdEye` i executa `sudo /tmp/HeimdEye/deploy/install.sh`.
   Després, a `/etc/apache2/sites-available/heimdeye.conf`: `ServerName heimdeye-staging.julioantonio.cat`
   (el `Require ip` ja és el del `cloudflared`, 192.168.20.7) i `systemctl reload apache2`.
3. **BBDD i .env.** Crea usuari i BBDD `heimdeye` al Postgres local (contrasenya `openssl rand -hex 24`).
   Parteix de `deploy/staging/env.staging.example` per al `.env` de `/var/www/heimdeye/shared/`: copia
   `APP_KEY` i la resta de claus del `.env` de producció (demana-les a Jaume; **no les posis a cap
   fitxer del repositori ni al xat**), canvia `DB_PASSWORD`, `APP_URL`, `HEIMDEYE_ENVIRONMENT=staging`
   i `CF_ACCESS_*` (veure pas 6). La clau SSH amb què HeimdEye entra als equips i `students.csv`
   van a `/var/lib/heimdeye/` com a [produccio.md](produccio.md#2-base-de-dades-secrets-i-claus).
4. **Còpia de producció.** A producció, un usuari amb `sudo -n -u heimdeye` només per a
   `/var/www/heimdeye/current/deploy/staging/dump.sh` (línia a `/etc/sudoers.d/heimdeye-staging`, validada
   amb `visudo -cf`). A staging, una clau SSH nova només per a això (`/var/lib/heimdeye/.ssh/id_prod`),
   autoritzada a producció; escriu `usuari@192.168.20.12` a `/var/lib/heimdeye/prod-ssh`
   i fes servir `-i` a través de `~/.ssh/config` per a aquell host.
5. **Primer desplegament i serveis.**
   `sudo -u heimdeye -H env SKIP_CI=1 /tmp/HeimdEye/deploy/deploy.sh <sha de main>`, després
   `sudo -u heimdeye -H /var/www/heimdeye/current/deploy/staging/refresca.sh`, i
   `systemctl enable --now heimdeye-queue@{1..2} heimdeye-deploy.timer` (**sense** `heimdeye-schedule.timer`).
   Drop-in del timer: `systemctl edit heimdeye-deploy.service` amb
   `[Service]` + `Environment=HEIMDEYE_PR_LABEL=staging`.
6. **Cloudflare** (Claude Code hi té accés):
   ruta del túnel `heimdeye-staging.julioantonio.cat` → `http://<IP del contenidor>:8080` i una aplicació
   d'Access pròpia (només Jaume), seguint [cloudflare.md](cloudflare.md). Posa el seu `AUD` al `.env` i
   `php artisan config:cache`.
7. **Comprovació.** `curl -s localhost:8080/up` dins el contenidor; entrar a la URL amb Google; veure
   les dades de producció; etiquetar un PR de prova amb `staging` i comprovar (≤ 5 min, `journalctl -u
   heimdeye-deploy`) que es desplega; treure-hi l'etiqueta. Informa de cada resultat.

## Ús diari

```bash
# Provar un PR: poseu-hi l'etiqueta "staging" (a GitHub). En ≤ 5 min és a staging.
# Abans, si toca migracions: dades fresques de producció
sudo -u heimdeye -H /var/www/heimdeye/current/deploy/staging/refresca.sh
journalctl -u heimdeye-deploy -n 30          # què ha desplegat
sudo systemctl start heimdeye-deploy         # desplegar ara
```

Quan el PR es fusiona, treu-ne l'etiqueta (o es tanca sol: un PR tancat ja no compta).

## Desfer una migració dolenta a producció

```bash
sudo -u heimdeye -H /var/www/heimdeye/current/deploy/rollback.sh --bd   # la còpia més recent
sudo systemctl restart 'heimdeye-queue@*'
```

Restaura la còpia feta just abans del desplegament (les dades escrites des d'aleshores es perden; per
això demana confirmació) i torna al release anterior. Sense `--bd`, `rollback.sh` només canvia el codi.
