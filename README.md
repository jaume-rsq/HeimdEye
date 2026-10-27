# HeimdEye
Aplicació de monitoratge automàtic de tasques d'alumnes.

Cada tasca es verifica amb checks binaris que el servidor executa cada nit (o a demanda)
contra els equips dels alumnes. El disseny complet és al document de disseny del projecte.

## Estat

Fase 4: base de dades, seeders, runner de checks (executors `local`, `ssh` i `ansible`), accions
del professor i la primera part de la interfície web (Filament): la gestió d'accions i de checks.

## Posada en marxa (desenvolupament)

Requisits: PHP 8.3+, Composer i PostgreSQL (també funciona amb MariaDB o SQLite).

```bash
composer install
cp .env.example .env
php artisan key:generate
# Edita .env: credencials de la BBDD, HEIMDEYE_ADMIN_NAME i HEIMDEYE_ADMIN_EMAIL
php artisan migrate --seed
php artisan test
php artisan serve      # http://localhost:8000/admin
```

En local (`APP_ENV=local`), el seeder crea l'usuari de prova `admin@heimdeye.test` amb
contrasenya `password`.

### CI

Cada PR i cada push a `main` passa els tests a GitHub Actions (`.github/workflows/ci.yml`) dues
vegades: amb SQLite en memòria i amb PostgreSQL, perquè hi ha errors que només surten a
Postgres. També hi corre Pint, de moment només com a avís. Per provar Postgres en local:

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_DATABASE=heimdeye_test DB_USERNAME=heimdeye DB_PASSWORD=heimdeye php artisan test
`migrate --seed` només carrega el Bloc 2. Els altres blocs tenen el seu seeder, que es pot
tornar a executar sense duplicar res (no canvia el codi dels checks que ja tenen versions):

```bash
php artisan db:seed --class=Block05FirewallSeeder   # Bloc 5: tallafocs Linux o MikroTik
php artisan db:seed --class=Block06DnsSeeder        # Bloc 6: DNS (i l'acció d'accés a ns1)
```

### Autenticació

`HEIMDEYE_AUTH` tria com s'entra al panell `/admin`. En els dos casos només hi entren els
administradors i professors actius de la taula `usuaris` (no hi ha autoregistre).

- `local`: formulari de correu i contrasenya. Per a desenvolupament.
- `cloudflare`: l'app és darrere un túnel de Cloudflare amb una aplicació de Cloudflare Access
  que fa el login de Google Workspace. HeimdEye valida el JWT de la capçalera
  `Cf-Access-Jwt-Assertion` amb les claus de l'equip (`CF_ACCESS_TEAM_DOMAIN`, p. ex.
  `equip.cloudflareaccess.com`) i l'audiència de l'aplicació (`CF_ACCESS_AUD`), i busca
  l'usuari pel correu. Convé que el tallafoc només deixi arribar al port 80 el `cloudflared`.

### Cua

Les accions llançades des del panell i els checks de la nit van a la cua (`HEIMDEYE_QUEUE`,
per defecte `checks`), un treball per alumne. En producció calen workers en marxa; amb 4 en
paral·lel, 20 alumnes amb un playbook de 30 s acaben en uns 3 minuts. En producció són les
instàncies `heimdeye-queue@{1..4}` de `deploy/systemd/` (vegeu [Producció](#producció)).

Cada treball porta el seu temps màxim (el de la versió més 60 s), però el worker no pot tenir
un `--timeout` més curt. A més, `DB_QUEUE_RETRY_AFTER` (al `.env`) ha de ser més llarg que
qualsevol treball: si no, la cua el dona per perdut i el torna a repartir mentre encara corre.
El temps màxim d'una versió és com a molt 3600 s, i per això el valor per defecte és 3700.

En desenvolupament n'hi ha prou amb `QUEUE_CONNECTION=sync` o amb `php artisan queue:work --queue=checks`.

### Alumnes

El seeder llegeix `database/seeders/data/students.csv`, que **no es versiona** perquè conté
dades personals (el repositori és públic). Hi pots enganxar la llista de l'aula tal qual,
separada per tabuladors o per comes, amb aquesta capçalera:

```
Xarxa	Taula	Alumne	eMail	alum
192.168.20.32/28	Taula01	Cognoms, Nom	usuari@iesjulioantonio.cat	alum32
```

L'usuari és la part del correu abans de l'`@`, i X = 20 + número de taula. Si el fitxer no
existeix, es carrega `students.example.csv` (si és buit, no es crea cap alumne). Els tests
sempre fan servir els 10 alumnes ficticis de `tests/fixtures/students.csv`.

Cada alumne rep `xarxa_aula` (columna Xarxa) i `nom_alum` (columna alum) com a variables pròpies.

Durant el curs, la mateixa llista s'importa des del panell (Alumnes → **Importar llista**):
s'enganxa o s'arrossega el fitxer (es llegeix al navegador, no es puja), es previsualitzen els
alumnes nous, els canvis i els que ja no hi són, i només s'aplica en confirmar. Els que no hi són
es poden marcar inactius; no s'esborra mai cap alumne. El seeder fa servir el mateix servei
(`App\Support\StudentImporter`) però no desactiva ningú.

## Producció

HeimdEye corre a la VM del Zabbix (192.168.20.12, Ubuntu 26.04, PHP 8.5), amb el Postgres
local d'aquesta màquina i darrere un túnel de Cloudflare (el `cloudflared` és a 192.168.20.7).
Tot el que cal per a la màquina és a `deploy/`, sense cap secret.

| Peça | Com |
| --- | --- |
| Web | Apache al port 8080 (el 80 és del Zabbix) → pool de php-fpm propi amb l'usuari `heimdeye` |
| Workers | `heimdeye-queue@{1..4}`: usuari `heimdeye`, sistema de fitxers de només lectura menys `storage` i `/var/lib/heimdeye`, `/tmp` privat, 1 GB de memòria |
| Ronda nocturna | `heimdeye-schedule.timer` (`schedule:run` cada minut) |
| Desplegament | `heimdeye-deploy.timer`: cada 5 minuts, `deploy/deploy.sh` |
| Secrets | `/var/www/heimdeye/shared/.env` (plantilla: `deploy/env.production.example`), la clau SSH a `/var/lib/heimdeye/.ssh/` |

### Desplegament continu

Cada commit de `main` amb els jobs `tests-sqlite` i `tests-pgsql` del CI en verd es desplega
sol en uns 5 minuts. `deploy.sh` mira el commit de `main` (`git ls-remote`) i el CI a l'API
pública de GitHub, i no desplega si hi ha feines a la cua o falten menys de 20 minuts per a la
ronda nocturna. Cada versió va a `releases/<data>-<sha>`:

1. `git clone` del commit, amb `.env` i `storage` enllaçats a `shared/`.
2. `composer install --no-dev`.
3. Còpia de la BBDD amb `pg_dump` a `/var/www/heimdeye/backups/` (se'n guarden 10).
4. `migrate --force`, `optimize` i `filament:optimize`.
5. `current` passa a apuntar al release nou, es recarrega php-fpm i `queue:restart`.
6. Si `/up` no respon, torna al release anterior.

Si falla, el commit queda apuntat a `failed-sha` i no es torna a provar fins que n'hi hagi un
de nou (o amb `FORCE=1`). Ordres útils:

```bash
journalctl -u heimdeye-deploy -n 50                                  # què ha fet
sudo systemctl start heimdeye-deploy                                 # ara, sense esperar el timer
sudo -u heimdeye -H /var/www/heimdeye/current/deploy/rollback.sh     # torna al release anterior
```

`rollback.sh` no desfà migracions: per això convé que siguin compatibles amb la versió anterior.
Si cal, la còpia es restaura amb `pg_restore --clean -d heimdeye <fitxer>`.

### Instal·lació (una vegada)

```bash
git clone https://github.com/jaume-rsq/HeimdEye.git /tmp/HeimdEye
sudo /tmp/HeimdEye/deploy/install.sh
```

`install.sh` instal·la els paquets (ansible-core, python3-winrm, jq...), crea l'usuari
`heimdeye`, les carpetes, el pool de php-fpm, el lloc d'Apache, el sudoers (només
`systemctl reload php8.5-fpm`) i les unitats de systemd. Després:

```bash
# 1. BBDD
sudo -u postgres psql -c "create user heimdeye password 'CONTRASENYA'" -c "create database heimdeye owner heimdeye"
# 2. Secrets: APP_KEY, DB_PASSWORD, APP_URL, CF_ACCESS_*, HEIMDEYE_ADMIN_*
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"     # valor per a APP_KEY
sudo -u heimdeye nano /var/www/heimdeye/shared/.env
# 3. Clau SSH dels checks i llista d'alumnes (mai al repositori)
sudo install -o heimdeye -g heimdeye -m 600 ~/.ssh/id_ed25519 /var/lib/heimdeye/.ssh/id_ed25519
sudo install -o heimdeye -g heimdeye -m 644 ~/.ssh/id_ed25519.pub /var/lib/heimdeye/.ssh/id_ed25519.pub
sudo install -o heimdeye -g heimdeye -m 600 students.csv /var/lib/heimdeye/students.csv
# 4. Primer desplegament i dades inicials
sudo -u heimdeye -H env SKIP_CI=1 /tmp/HeimdEye/deploy/deploy.sh
cd /var/www/heimdeye/current && sudo -u heimdeye php artisan db:seed --force
# 5. Serveis
sudo systemctl enable --now heimdeye-queue@{1..4} heimdeye-schedule.timer heimdeye-deploy.timer
```

Al Cloudflare: el túnel envia `heimdeye.julioantonio.cat` a `http://192.168.20.12:8080`, i l'aplicació
d'Access fa el login de Google Workspace amb una regla Bypass per a `/leaderboard`. El lloc
d'Apache del port 8080 només accepta 192.168.20.7 i la mateixa màquina (`Require ip`), perquè la
VM no té tallafoc actiu. `HEIMDEYE_TRUSTED_PROXIES`
fa que Laravel cregui la capçalera `X-Forwarded-Proto` (HTTPS) només si ve del `cloudflared`.

## Docker

`docker/` té una imatge (FrankenPHP amb PHP 8.5, ssh i Ansible) i un `compose.yaml` amb el web,
2 workers de la cua, el scheduler i PostgreSQL 18. És l'opció per aixecar HeimdEye en una màquina
nova sense instal·lar res més que Docker, i és la base de la demo.

```bash
cd docker
cp env.example .env       # APP_KEY (php artisan key:generate --show), DB_PASSWORD, HEIMDEYE_ADMIN_*
docker compose up -d --build
docker compose --profile tunnel up -d   # opcional: cloudflared dins el Compose (TUNNEL_TOKEN)
```

L'app queda al port `HEIMDEYE_PORT` (8080). El contenidor `app` migra en arrencar i, amb
`HEIMDEYE_SEED=true`, omple la BBDD la primera vegada. Els contenidors corren com un usuari sense
privilegis, sense capabilities i amb `no-new-privileges`; els workers tenen límits de CPU,
memòria i processos. `docker compose down -v` ho esborra tot, BBDD inclosa.

## Mode demo

Amb `HEIMDEYE_DEMO=true` (`App\Support\Demo`) la instància és pública: el login és un botó que
crea un professor visitant (`visitant-xxxxxx@demo.heimdeye.invalid`) amb tots els mòduls
assignats. Pensat per a una VM aïllada, sense secrets reals i que es restaura cada nit (vegeu el
document de seguretat de la demo).

| Què | Visitant | Administrador |
| --- | --- | --- |
| Checks i accions | Crear, provar i executar. Editar o esborrar només els que ha creat ell | Tot |
| Cursos, mòduls, blocs, tasques, alumnes, equips, variables | Només lectura | Tot |
| Usuaris i credencials | No els veu | Tot |
| Execucions | Màxim `HEIMDEYE_DEMO_MAX_TIMEOUT` s (30) i `HEIMDEYE_DEMO_RUNS_PER_HOUR` per hora (60) | Màxim de temps igual |

`heimdeye:demo-cleanup` (cada hora) esborra els visitants sense activitat en les darreres
`HEIMDEYE_DEMO_VISITOR_HOURS` hores (2) i els checks i les accions que havien creat.

### Contenidor de proves i alumnes ficticis

```bash
cd docker
docker compose -f compose.yaml -f compose.demo.yaml up -d --build
```

`compose.demo.yaml` activa el mode demo i hi afegeix:

| Servei | Què és |
| --- | --- |
| `sandbox` | Contenidor de proves: hi corre tot el codi dels checks i les accions (bash, ssh, `ansible-playbook`). La mateixa imatge, amb un `sshd` sense root al port 2222. Sense `.env` ni accés a la BBDD, sistema de fitxers de només lectura menys `/tmp`, sense capabilities i amb límits |
| `alumne01`…`alumne04` | Alumnes ficticis: Ubuntu amb SSH, l'usuari `alumne` amb `sudo` i Python per a Ansible |
| `keys` | Genera la primera vegada les claus worker → sandbox i sandbox → alumnes |

El worker fa servir `HEIMDEYE_RUNNER_WRAPPER=heimdeye-sandbox` (`docker/sandbox/heimdeye-sandbox`):
cada execució hi copia els fitxers temporals (inventari, playbook), l'executa per SSH amb el temps
màxim i les variables, i els esborra. La clau dels alumnes només és al sandbox. Les xarxes
`runner` (worker ↔ sandbox) i `alumnes` (sandbox ↔ alumnes) són internes: sense sortida a Internet.

## Tutorial

El tutorial de HeimdEye és un curs a part (`TutorialSeeder`): el curs **Tutorial**, el cicle
**TUT** i dos mòduls, *TUT1 Primers passos amb els checks* i *TUT2 Accions*, amb 4 alumnes
ficticis que tenen cadascun un `servidor` (els contenidors `alumne01`…`alumne04` de la demo).
L'estructura és un exemple d'ús: tipus d'equip, variable de mòdul, credencial, checks local i
ssh amb paràmetres i info, un check opcional per duplicar i accions Ansible i ssh amb checks de
verificació. És la base de dades de la demo (`DemoSeeder`).

Els textos són a l'app (enunciats, descripcions, info) i [docs/tutorial.md](docs/tutorial.md)
se'n genera: `php artisan heimdeye:tutorial` (amb el tutorial a la BBDD).

## Variables

Totes les variables són a la taula `variables`, amb un àmbit (`abast`): `global`, `curs`
(`curs_id`) o `modul` (`modul_id`). El valor pot ser una plantilla que en fa servir d'altres:
`{{x}}`, `{{ xarxa_aula | host(3) }}` (tercera adreça útil), `{{ xarxa_aula | host(-1) }}`
(darrera), `{{ ip_pve | octet(4) }}`.

Prioritat, de menys a més: global, curs, mòdul, camps de l'alumne (`usuari`, `nom_complet`,
`num_taula`, `x`, `email`) i variables pròpies de l'alumne (`alumnes.variables`). Una variable
pròpia amb el mateix nom que una de mòdul és la manera de fer una excepció per a un alumne.

Els noms segueixen un prefix segons el tipus:

| Prefix | Per a | Exemples |
| --- | --- | --- |
| `xarxa_` | xarxes CIDR | `xarxa_aula`, `xarxa_lan` (172.X.0.0/22), `xarxa_dmz` (10.X.0.0/22) |
| `ip_` | adreces | `ip_pve`, `ip_router`, `ip_dns`, `ip_zabbix` |
| `nom_` | noms | `nom_taula` (Taula01), `nom_dns`, `nom_domini`, `nom_alum` |
| `num_` | números | `num_base` |

## Model de dades

Curs (`cursos`) → Cicle (`cicles`) → Mòdul (`moduls`) → Bloc (`blocs`) → Tasca (`tasques`) → Check (`checks`).
Els noms de taules i camps són en català (sense accents); les classes PHP es mantenen en anglès
(`Module`, `Student`...) i cada model declara la seva `$table`.

- `moduls`: oficial o lliure (`combina` llista els mòduls oficials que agrupa), amb la
  credencial per defecte. Les variables són a `variables` (vegeu «Variables»).
- `alumnes` pertanyen a un curs i un cicle i es matriculen a mòduls (`modul_alumne`).
  Els professors s'assignen a mòduls (`modul_professor`).
- `checks`: tipus obligatori/opcional, punts, `es_actiu` (null = segueix `actiu_des`/`actiu_fins`),
  `data_limit` i bonus de puntualitat en %. El codi és a `versions_check` (vegeu «Codi» més avall).
- `execucions`: un lot per nocturna, execució manual o acció. `execucions_check`: log de cada
  execució. `check_alumne`: estat actual per (check, alumne), amb l'estat del professor (preval
  sobre l'automàtic), comentari i primera data d'assoliment.

## Codi: versions, executors, capçaleres i paràmetres

Els checks i les accions funcionen igual: tenen un **executor** fix i el codi va per
**versions** (`versions_check`, `versions_accio`). Cada edició crea una versió nova, la vigent
és la de `versio` més alta i cada execució apunta a la versió que es va fer servir, de manera que
sempre se sap quin codi exacte va córrer.

| On | Camp | Per a què |
| --- | --- | --- |
| check / acció | `executor` | `local`, `ssh`, `ansible` (o `http`, encara no implementat). No es pot canviar un cop creat: si canvia l'executor, és un check o una acció diferent. |
| versió | `codi` | El que s'executa: un script bash o un playbook, amb les capçaleres (vegeu més avall). |
| versió | `parametres` | Paràmetres declarats: `[{name, label, default}]`. |
| versió | `temps_max_seg` | Temps màxim d'execució; després el resultat és `timeout`. |
| versió d'acció | `checks_verificacio` | Ids dels checks que es llancen després d'executar l'acció (no en simular). |

### Contracte del codi

- **Resultat:** el codi de sortida 0 vol dir assolit (`pass`); qualsevol altre, `fail`.
  El missatge és la línia `HEIMDEYE_MSG: ...` si n'hi ha, o la primera línia de la sortida.
- **Omès:** amb `local` i `ssh`, un check que escriu una línia `HEIMDEYE_OMES: motiu` dona
  `omes`, surti com surti: ara no s'aplica (per exemple, l'alumne fa servir el router MikroTik i
  el check és del router Linux, que té la mateixa IP i està apagat). L'execució queda a
  l'historial en gris, però l'estat de l'alumne, les dates d'assoliment i els punts no canvien, i
  no compta com a darrera execució a les graelles ni als filtres.
- **Variables de l'alumne:** arriben com a entorn `HE_<NOM>` en majúscules: `HE_X`,
  `HE_NUM_TAULA`, `HE_USUARI`, `HE_IP_PVE`, `HE_IP_ROUTER`... Són totes les variables de
  l'alumne (vegeu «Variables» i `App\Support\VariableResolver`). A Ansible són variables de
  grup de l'alumne (`{{ ip_pve }}`, `{{ nom_taula }}`...).
- **Paràmetres:** `HE_PARAM_<NOM>` a `local` i `ssh`; a Ansible, una variable amb el seu nom.
- **Secrets del mòdul:** el codi només rep els que esmenta pel nom, com a paraula sencera
  (`HE_SECRET_<NOM>` o la variable Ansible), i a la sortida desada surten com a `********`.
  Cap secret no pot arribar en clar a un equip d'alumne, on l'alumne és root: amb `ssh`, el
  codi no en pot esmentar cap; amb Ansible, només dins una expressió amb `password_hash` (a
  l'equip hi arriba el hash) o en una tasca amb `delegate_to: localhost`. Si no, el codi no es
  pot desar ni executar. La comprovació és de text i, en cas de dubte, rebutja.

### Capçaleres

Les primeres línies de comentari del codi poden declarar on i amb quina credencial s'executa,
amb el format `# clau: valor`. Són comentaris normals, així que l'script es pot provar a mà tal
com està. Es llegeixen fins a la primera línia de codi; els altres comentaris s'ignoren.

```bash
# hosts: pve
# credencial: SSH jaume
out=$(sudo -n qm list) || { echo "No es pot executar qm list"; exit 1; }
```

- `# hosts: <grups>`: equips on s'executa el codi (executor `ssh`, obligatòria). Són grups
  Ansible del mòdul (`pve`, `vm_router`...) o noms de tipus d'equip, separats per `:` o comes.
  Si falta, o un grup no existeix, el codi no es pot desar i l'execució dona `error`. A Ansible
  fa aquesta feina el `hosts:` de cada play, amb les mateixes regles. `all`, comodins i
  variables no hi són permesos: cal dir els grups concrets.
- `# credencial: <nom>`: credencial a fer servir en lloc de la per defecte (pel nom). Si no
  existeix, `error`.

### Executors

| Executor | On s'executa | Equips | Credencial |
| --- | --- | --- | --- |
| `local` | Al servidor HeimdEye, amb `bash -s` (ping, dig, curl, o `ssh` fet pel mateix script). | Els tria el codi amb les variables (`$HE_IP_ROUTER`...). | La de `# credencial:` o la del mòdul. Una credencial SSH arriba com a `HE_SSH_USER` i `HE_SSH_KEY` (ruta a la clau); una de contrasenya (WinRM, API), com a `HE_CRED_USER` i `HE_CRED_PASSWORD` (emmascarada). |
| `ssh` | A cada equip de `# hosts:`, per SSH, un rere l'altre. Les variables `HE_*` s'exporten al principi de l'script remot. | Els de l'alumne dels grups de `# hosts:` (i els globals, si el grup ho és). Han de ser equips SSH: un Windows (WinRM) dona `error`. | La de `# credencial:`, o la del tipus d'equip, o la del mòdul. Port i usuari de `variables_connexio` (`ansible_port`, `ansible_user`) si n'hi ha. |
| `ansible` | `ansible-playbook` al servidor, un per alumne, amb l'inventari generat a partir dels tipus d'equip. | Els dels grups del `hosts:` dels plays: l'inventari només porta aquests. | La de cada tipus d'equip (o la del mòdul). |

Amb `ssh` i diversos equips, el resultat és `pass` només si passa a tots; si no, és el del
primer equip que falla, i el missatge comença pel nom de l'equip (`router01: ...`). La sortida
desada porta un bloc per equip.

Codis de sortida especials: amb `ssh`, el 255 (no connecta, clau rebutjada...) és
`unavailable`. Un codi que no es pot executar (falta una variable, capçalera o credencial) és
`error`. Amb Ansible, si cap equip de l'alumne no ha executat res (el `hosts:` no en
coincideix cap), també és `error`, mai `ok` ni `pass`. Un check d'Ansible que canvia alguna
cosa (`changed`) dona `error`: un check només llegeix (cal `changed_when: false` a les tasques
`command`/`shell`).

Claus de host: ni l'executor `ssh` ni Ansible no desen les claus dels equips
(`StrictHostKeyChecking=no`, `UserKnownHostsFile=/dev/null`), perquè els alumnes reinstal·len
les VM sovint. Els scripts `local` que fan `ssh` tenen les mateixes opcions a `$HE_SSH_OPTS`.

### Paràmetres

Serveixen perquè un mateix codi faci coses semblants amb valors diferents, sense tocar-lo:
per exemple, `min_vms` en un check de VMs o `usuari` en una acció.

```json
[{"name": "min_vms", "label": "Mínim de VMs", "default": 1}]
```

- Un **check** s'executa sol (cada nit), així que tots els seus paràmetres necessiten `default`;
  si en falta cap, el resultat és `error`.
- Una **acció** pot demanar els valors en el moment d'executar (`--param=usuari=jaume`); si no
  se'n dona cap, s'usa el `default`. Un paràmetre desconegut o sense valor atura l'execució. Els
  valors fets servir es desen a cada `execucions_accio`.
- Un valor pot fer servir variables de l'alumne (`{{ip_pve}}`, `{{ xarxa_aula | host(3) }}`):
  es calcula per a cada alumne abans d'executar.

Vegeu els exemples a `database/seeders/Block02ProxmoxSeeder.php` i `HostsSeeder.php`.

## Runner

```bash
php artisan heimdeye:run                       # tots els checks actius, ara, i desa resultats
php artisan heimdeye:run --block=2 --student=rcano
php artisan heimdeye:run --check=3 --dry       # prova: mostra resultats, no desa res
php artisan heimdeye:run --nightly             # com la nocturna: tot a la cua
php artisan queue:work --queue=checks          # worker que executa la cua
```

La nocturna la llança el scheduler (`HEIMDEYE_NIGHTLY_AT`, per defecte 02:00); cal el cron de Laravel:
`* * * * * cd /ruta/heimdeye && php artisan schedule:run >> /dev/null 2>&1`.

- Estats: `pass` (sortida 0), `fail`, `error` (el check no es pot executar), `timeout`,
  `unavailable` (ssh no connecta: codi 255) i `omes` (no s'aplica ara; vegeu el contracte del
  codi). A la cua, `unavailable` i `timeout` es reintenten un cop al cap de
  `HEIMDEYE_RETRY_AFTER` segons (180).
- Cada execució es desa a `execucions_check`; `check_alumne` guarda l'estat actual i la primera
  data d'assoliment, que no es torna a tocar.
- Cada check i alumne és una execució a part (un script o un `ansible-playbook`) i un treball de
  la cua propi (`RunCheck`), amb la versió vigent quan es posa a la cua: és la que s'executa i
  la que queda registrada. Si el treball falla abans de tenir resultat, queda una execució en
  estat `error` amb el motiu. Executat a mà (sense `--queue`) no hi ha reintents.
- Clau SSH: la de la credencial (vegeu la taula d'executors) si en té una de desada; si no,
  `HEIMDEYE_SSH_KEY_PATH`; si tampoc, la clau per defecte de l'usuari que executa el worker.
- Aïllament: `HEIMDEYE_RUNNER_WRAPPER` embolcalla cada execució, per exemple
  `systemd-run --uid=heimdeye-runner --pipe --wait --collect --quiet -p MemoryMax=256M -p CPUQuota=50%`.
  Amb `systemd-run` s'hi afegeixen `RuntimeMaxSec` i les variables (`--setenv`). L'usuari del
  sandbox ha de poder llegir la clau SSH i els fitxers temporals que crea el runner (inventari,
  playbook, claus), que són al directori temporal del sistema amb permisos 0700/0600.

### Checks al panell

Menú Checks → Llista de checks (`/admin/checks`), Nou check i Històric de checks. Funcionen com
les accions (vegeu [Accions](#accions)), amb aquestes diferències:

- Una versió només corregeix el codi (codi, paràmetres i temps màxim). Els assoliments no es
  recalculen mai i no hi ha botó per fer-ho: per comprovar una cosa nova, es crea un check nou.
  Els punts, les dates, la tasca i el tipus són del check i canviar-los no crea cap versió.
- Icona d'estat a la llista, segons la darrera execució de cada alumne actiu: verd = tots `pass`
  amb la versió vigent, blau = n'hi ha que encara no l'han assolit, no s'han comprovat o s'han
  comprovat amb una versió anterior, vermell = cap alumne l'ha assolit, taronja = algun
  `error`, `unavailable` o `timeout` (no s'ha pogut comprovar), gris = mai executat. Filtres per
  curs, mòdul, bloc, executor, actiu avui i estat de la darrera execució per a tots o algun alumne.
- **Verificar ara** posa el check a la cua per als alumnes triats (per defecte tots), un treball
  per alumne amb la versió vigent. El resultat compta igual que el de la nit. No hi ha simulació
  (un check només llegeix) ni paràmetres per execució: sempre els valors per defecte, calculats
  per a cada alumne.
- A l'historial del check, la graella per alumne: verd si està assolit amb la versió vigent, verd
  clar amb el número si ho està amb una d'anterior, vermell si `fail`, taronja si no s'ha pogut
  comprovar, gris si no s'ha executat mai. «Verificar amb la vN» surt només si hi ha alumnes
  comprovats amb una versió anterior, i els porta marcats tots, també els que fallaven: la
  versió corregida els pot donar per bons.
- A l'editor, **Provar** executa el codi del formulari sense desar-lo per als alumnes triats,
  per la cua. No es desa ni compta per als alumnes. Una versió nova no es pot desar sense
  haver-la provada tal com és, ni si no passa les comprovacions de `hosts:` i de secrets.
- Editar i esborrar un check: un administrador o un professor del mòdul.
- **Posició** `bloc.tasca.check` (5.2.1): número del bloc, posició de la tasca i posició del check.
  És l'ordre en què l'alumne els ha d'anar assolint, i el de la llista i del Seguiment.
- A la llista, cada check ocupa dues línies (nom i descripció; tasca, executor, punts, actiu,
  versió) i les icones van agrupades en un quadre. «Modificat» diu «fa 20 minuts» les dues
  primeres hores; després, la data i l'hora.
- **Info per a l'alumne** (Markdown): objectiu del test i justificació. Es mostra en una finestra
  des del check (botó Info del Seguiment) amb el codi de la versió vigent amb les dades de
  l'alumne: `$HE_VAR`, `${HE_PARAM_NOM}` o, a Ansible, `{{ var }}` es canvien pel seu valor; els
  secrets i les credencials no es mostren mai.
- Esborrar un check l'esborra del tot (no hi ha paperera): versions, execucions, estat dels
  alumnes i assoliments. L'avís diu quant es perd i quines accions el fan servir per verificar.
  Esborrar una acció també avisa de les versions i execucions que es perden.

## Equips, inventari Ansible i secrets

Cada mòdul defineix els seus **tipus d'equip** (`tipus_equip`): grup Ansible, nom del host i
adreça (plantilles amb variables de l'alumne), variables de connexió i credencial. Els de
tipus `alumne` en generen un per alumne; els `global` (com el Zabbix) un de sol.
A partir d'aquí es genera l'inventari en JSON, equivalent a `smix2.ini`: grups per tipus
(`pve`, `vm_router`, `vm_dns_bind`...), un grup per alumne (`taula01`...) amb les seves
variables, i `alumnes`. Vegeu `database/seeders/HostsSeeder.php`.

Els **secrets** del mòdul (`secrets`, xifrats amb `APP_KEY`) fan de vault: un playbook o
script rep només els que anomena (com a variable Ansible o `HE_SECRET_*`), i a la sortida
desada apareixen com a `********`. Els valors s'omplen des de l'administració, mai al repositori.

Els checks `ssh` fan servir aquests mateixos grups amb la capçalera `# hosts:`, i els checks
també poden ser playbooks (`executor = ansible`).

### Al panell (menú Inventari)

- **Vista de conjunt** (`/admin/inventari`): alumnes × equips o alumnes × variables d'un mòdul,
  amb l'origen de cada valor i les plantilles que no es poden calcular en vermell.
  El botó ▶ prova la connexió d'un equip, d'una columna (un tipus d'equip per a tots els alumnes),
  d'un alumne (tots els seus equips) o d'un equip global, amb el mateix inventari que les accions:
  `ping` als Linux, `win_ping` als Windows (cal la col·lecció `ansible.windows`) i, a les API
  (`httpapi`), només que el port sigui obert. Va per la cua i no desa res.
- **Alumnes** (`/admin/alumnes`): dades, variables pròpies i l'inventari calculat de cada alumne
  (variables, equips, inventari Ansible amb les contrasenyes emmascarades i activitat).
  **Importar llista** (`/admin/alumnes/importar`) carrega la llista de l'aula a un mòdul.
- **Equips i variables** (`/admin/moduls`): tipus d'equip, variables i secrets de cada mòdul.
  La previsualització calcula, per a l'alumne triat, el que hi ha al formulari sense desar-ho.
  En desar, si alguna plantilla no es pot calcular per a algun alumne, ho avisa. Els secrets
  no es tornen a mostrar: un camp buit vol dir que no canvia.
- **Variables comunes** (`/admin/variables`): les globals i les de cada curs, agrupades per prefix.
  Un nom només pot sortir una vegada a cada àmbit.
- **Credencials** (`/admin/credencials`): SSH (clau privada), WinRM i usuari/contrasenya, amb on
  es fan servir (mòduls, tipus d'equip i capçaleres `# credencial:`). El secret no es torna a mostrar.

### Al panell (menú Administració)

- **Usuaris i rols** (`/admin/usuaris`, només administradors): administrador (tot, inclosos els
  usuaris), professor (el panell sense usuaris, amb els mòduls que imparteix) i alumne (no entra al
  panell; els crea l'importador). Amb `HEIMDEYE_AUTH=cloudflare` l'usuari es reconeix pel correu de
  Google Workspace i no hi ha contrasenya. Ningú es pot canviar el rol, desactivar-se ni esborrar-se
  a si mateix; un usuari amb fitxa d'alumne no s'esborra des d'aquí.
- **Cursos** (`/admin/cursos`) i **Cicles** (`/admin/cicles`). Només hi ha un curs actual: marcar-ne
  un desmarca l'anterior. Un curs o un cicle amb mòduls o alumnes no es pot esborrar.
- **Mòduls i blocs** (`/admin/moduls-i-blocs`): dades del mòdul, professors, i els seus blocs amb
  les tasques (d'on pengen els checks). Un bloc o una tasca amb checks no es pot treure, i un mòdul
  amb checks, alumnes o accions no es pot esborrar. Els equips i les variables són a Inventari.
- **Alumnes** (Inventari): a més de la fitxa, es poden marcar actius o inactius en bloc. Esborrar un
  alumne esborra les seves execucions; si només ha deixat el curs, millor marcar-lo inactiu.

## Leaderboard

Classificació d'un mòdul del curs actual. Públic a `/leaderboard` (sense login: a Cloudflare Access cal
una regla de Bypass per a aquest camí), on només surt l'àlies de l'alumne o «Taula NN»; al panell
(menú Leaderboard) surt amb els noms reals.

- Compten els checks que ja han començat: no els desactivats a mà ni els que encara no han arribat a
  `actiu_des`. Els que ja han acabat continuen comptant.
- Cada check assolit dona els seus punts, amb el bonus de puntualitat si es va assolir abans de la
  data límit. L'estat del professor mana: assolit sense data dona els punts sense bonus; no assolit, 0.
- Columnes: punts, % d'obligatoris assolits, progrés per bloc i data del darrer assoliment.
- En cas d'empat, va davant qui hi va arribar abans (darrer assoliment més antic).

### Seguiment

Detall d'un alumne per a professors i admins (menú Seguiment, `/admin/seguiment`). S'hi arriba clicant
el nom al leaderboard del panell, des de la fitxa de l'alumne o des d'una cel·la de la graella d'alumnes
d'un check. L'URL guarda alumne, mòdul i filtres, i es pot compartir.

- Un admin veu tots els mòduls; un professor només els que imparteix (i els alumnes d'aquests mòduls).
- Una targeta per mòdul amb punts, posició, % d'obligatoris, progrés per bloc i dates.
- Taula del mòdul agrupada per bloc i tasca, filtrable per bloc, tasca, estat i només obligatoris:
  estat (amb la correcció del professor), darrera execució, missatge, primer assoliment, punts i comentari.
- Clicant una fila es veu la sortida de la darrera execució i l'historial reduït als canvis d'estat
  (amb la data de cada canvi i quantes execucions hi ha hagut amb aquell estat).
- Cada bloc es plega clicant-ne la línia, que mostra els agregats del bloc: checks assolits,
  obligatoris, punts, checks amb error, darrer assoliment i dates del bloc. La columna Pos. dona
  l'ordre dels checks.
- Cada check ocupa dues línies: a dalt el nom, si és obligatori i els punts, l'estat, el primer
  assoliment, els punts obtinguts i els botons (Info, Verificar, Corregir i ✎ editar, aquest
  només per a administradors i professors del mòdul); a sota la darrera execució (resultat, data,
  versió, durada i missatge). El comentari del professor, si n'hi ha, va en una tercera línia.
- Botons per verificar ara un check, una tasca o tot el mòdul (per a aquest alumne) i per corregir:
  forçar assolit / no assolit o tornar a automàtic, i deixar un comentari que veurà l'alumne.

### Vista de l'alumne

Panell propi a `/alumne` (menú «El meu seguiment»). Hi entren els usuaris amb rol alumne, actius i
amb fitxa d'alumne (l'importador ja els crea amb l'email de la llista). Amb Cloudflare Access entren
amb el seu compte de Google; en local, pel formulari de `/alumne/login`. Un alumne que obre `/admin`
(o `/`) va a `/alumne`, i un professor no pot entrar a `/alumne`.

És la pantalla de Seguiment de només lectura i sempre amb l'alumne connectat: els seus mòduls, els
checks amb l'estat, la darrera execució, el comentari del professor i el botó Info. No hi ha selector
d'alumne, Verificar, Corregir ni editar, i el servidor els rebutja encara que es cridin a mà.

## Accions

Les accions són scripts o playbooks que llança el professor (no puntuen): posar contrasenya,
instal·lar l'agent de monitoratge... Cada execució queda a `execucions_accio` per alumne, amb
l'estat (`ok`, `changed`, `failed`, `unavailable`, `error`, `timeout`) i la sortida emmascarada.

```bash
php artisan heimdeye:action "Contrasenya de l'usuari jaume" --student=alumne01 --simulate
php artisan heimdeye:action 4 --student=alumne01 --student=alumne02 --host=pve --param=usuari=jaume
php artisan heimdeye:action 4 --queue          # tots els alumnes, a la cua
```

- Cada alumne és una execució a part (un `ansible-playbook` o un script per alumne) i un
  treball de la cua propi: un alumne amb una variable que falta o un equip que no respon no
  afecta els altres, i el temps màxim de la versió és per alumne.
- S'executa la versió vigent quan es llança, encara que després se'n desi una altra.
- `--simulate` executa amb `--check --diff`: no canvia res i mostra què canviaria. Les tasques
  `command` i `shell` se salten en simular, així que una simulació verda no ho garanteix tot.
- `--host` limita els tipus d'equip als que fa servir el codi; `--param` dona valor als
  paràmetres declarats.
- Si la versió té `checks_verificacio`, quan l'acció acaba per a un alumne es llancen aquests
  checks per a ell (no en simular).

### Al panell

Menú Accions → Llista d'accions (`/admin/accions`):

- Filtres per curs (per defecte l'actual), mòdul, executor, nom i estat de la darrera execució
  real per a tots o algun alumne.
- Icona d'estat segons la darrera execució real (les simulacions no compten) de cada alumne
  actiu: verd = tots ok/changed amb la versió vigent, blau = encara falten alumnes o n'hi ha
  que la tenen d'una versió anterior, taronja = ha fallat per a algun, vermell = ha fallat per
  a tots, gris = mai executada.
- A l'historial, una graella amb un quadre per alumne: verd si la darrera execució va bé amb
  la versió vigent, verd clar amb el número de versió si va bé amb una d'anterior, taronja o
  vermell si ha fallat, gris si no s'ha executat mai. Només si hi ha alumnes amb una versió
  anterior surt el botó «Executar amb la vN», amb aquests alumnes ja marcats.
- Botons Versions, Historial, Editar darrera versió i Llançar (Simular, només Ansible, i Executar).
- Editar no modifica mai una versió: si canvia el codi, els paràmetres, el temps màxim o els
  checks de verificació, es crea la versió següent, amb un comentari opcional del canvi.
  L'executor i el mòdul no es poden canviar.
- Les simulacions no compten per a l'estat ni surten a l'historial: després de Simular es veu
  el resultat a «Darrera simulació» de l'acció.
- A l'editor (nova acció o editar), el botó **Provar** simula o executa el codi del formulari
  sense desar-lo, o executa un check per verificar-ne l'efecte. Els resultats surten a la secció
  Proves i no es desen enlloc. Una versió nova no es pot desar sense haver provat el codi tal com és,
  ni si no passa les comprovacions de `hosts:` i de secrets.
  Les proves van per la cua (un treball per alumne), perquè Cloudflare talla les peticions als
  100 s: la secció Proves les mostra «en cua» i s'omple a mesura que acaben. Cal un worker en marxa.
- Si una execució falla abans de tenir resultat (p. ex. falta un paràmetre), queda a l'historial
  en estat `error` i l'excepció va al log de Laravel.
- Si hi ha feines a la cua que cap worker no processa, el panell ho avisa a dalt de tot.
- En llançar per a un sol alumne, els paràmetres es mostren calculats amb les seves variables
  (`{{num_taula}}`...) i es poden modificar. Només s'envien els que difereixen del valor per defecte.
