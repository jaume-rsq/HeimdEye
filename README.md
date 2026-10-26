# HeimdEye
Aplicació de monitoratge automàtic de tasques d'alumnes.

Cada tasca es verifica amb checks binaris que el servidor executa cada nit (o a demanda)
contra els equips dels alumnes. El disseny complet és al document de disseny del projecte.

## Estat

Fase 4: base de dades, seeders, runner de checks (executors `local`, `ssh` i `ansible`), accions
del professor i la primera part de la interfície web (Filament): la gestió d'accions. Els checks,
el leaderboard i els CRUD surten al menú com a «aviat».

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

Les accions llançades des del panell van a la cua (`HEIMDEYE_QUEUE`, per defecte `checks`).
En producció cal un worker en marxa, p. ex. amb systemd:

```ini
# /etc/systemd/system/heimdeye-queue.service
[Unit]
Description=HeimdEye: worker de la cua
After=network.target postgresql.service

[Service]
User=www-data
WorkingDirectory=/var/www/heimdeye
ExecStart=/usr/bin/php artisan queue:work --queue=checks,default --sleep=3 --tries=1
Restart=always

[Install]
WantedBy=multi-user.target
```

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
- **Variables de l'alumne:** arriben com a entorn `HE_<NOM>` en majúscules: `HE_X`,
  `HE_NUM_TAULA`, `HE_USUARI`, `HE_IP_PVE`, `HE_IP_ROUTER`... Són totes les variables de
  l'alumne (vegeu «Variables» i `App\Support\VariableResolver`). A Ansible són variables de
  grup de l'alumne (`{{ ip_pve }}`, `{{ nom_taula }}`...).
- **Paràmetres:** `HE_PARAM_<NOM>` a `local` i `ssh`; a Ansible, una variable amb el seu nom.
- **Secrets del mòdul:** el codi només rep els que esmenta pel nom (`HE_SECRET_<NOM>` o la
  variable Ansible), i a la sortida desada surten com a `********`.

### Capçaleres

Les primeres línies de comentari del codi poden declarar on i amb quina credencial s'executa,
amb el format `# clau: valor`. Són comentaris normals, així que l'script es pot provar a mà tal
com està. Es llegeixen fins a la primera línia de codi; els altres comentaris s'ignoren.

```bash
# hosts: pve
# credencial: SSH jaume
out=$(sudo -n qm list) || { echo "No es pot executar qm list"; exit 1; }
```

- `# hosts: <grups>`: equips on s'executa el codi (executor `ssh`). Són grups Ansible del
  mòdul (`pve`, `vm_router`...) o noms de tipus d'equip, separats per `:` o comes. Si falta, o
  un grup no existeix, l'execució dona `error`.
- `# credencial: <nom>`: credencial a fer servir en lloc de la per defecte (pel nom). Si no
  existeix, `error`.

### Executors

| Executor | On s'executa | Equips | Credencial |
| --- | --- | --- | --- |
| `local` | Al servidor HeimdEye, amb `bash -s` (ping, dig, curl, o `ssh` fet pel mateix script). | Els tria el codi amb les variables (`$HE_IP_ROUTER`...). | La de `# credencial:` o la del mòdul. Una credencial SSH arriba com a `HE_SSH_USER` i `HE_SSH_KEY` (ruta a la clau); una de contrasenya (WinRM, API), com a `HE_CRED_USER` i `HE_CRED_PASSWORD` (emmascarada). |
| `ssh` | A cada equip de `# hosts:`, per SSH, un rere l'altre. Les variables `HE_*` s'exporten al principi de l'script remot. | Els de l'alumne dels grups de `# hosts:` (i els globals, si el grup ho és). Han de ser equips SSH: un Windows (WinRM) dona `error`. | La de `# credencial:`, o la del tipus d'equip, o la del mòdul. Port i usuari de `variables_connexio` (`ansible_port`, `ansible_user`) si n'hi ha. |
| `ansible` | `ansible-playbook` al servidor, amb l'inventari generat a partir dels tipus d'equip. | Els tria el `hosts:` del playbook. | La de cada tipus d'equip (o la del mòdul). |

Amb `ssh` i diversos equips, el resultat és `pass` només si passa a tots; si no, és el del
primer equip que falla, i el missatge comença pel nom de l'equip (`router01: ...`). La sortida
desada porta un bloc per equip.

Codis de sortida especials: amb `ssh`, el 255 (no connecta, clau rebutjada...) és
`unavailable`. Un codi que no es pot executar (falta una variable, capçalera o credencial) és
`error`.

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

- Estats: `pass` (sortida 0), `fail`, `error` (el check no es pot executar), `timeout` i
  `unavailable` (ssh no connecta: codi 255). A la cua, `unavailable` i `timeout` es reintenten
  un cop al cap de `HEIMDEYE_RETRY_AFTER` segons (180).
- Cada execució es desa a `execucions_check`; `check_alumne` guarda l'estat actual i la primera
  data d'assoliment, que no es torna a tocar.
- Clau SSH: la de la credencial (vegeu la taula d'executors) si en té una de desada; si no,
  `HEIMDEYE_SSH_KEY_PATH`; si tampoc, la clau per defecte de l'usuari que executa el worker.
- Aïllament: `HEIMDEYE_RUNNER_WRAPPER` embolcalla cada execució, per exemple
  `systemd-run --uid=heimdeye-runner --pipe --wait --collect --quiet -p MemoryMax=256M -p CPUQuota=50%`.
  Amb `systemd-run` s'hi afegeixen `RuntimeMaxSec` i les variables (`--setenv`). L'usuari del
  sandbox ha de poder llegir la clau SSH i els fitxers temporals que crea el runner (inventari,
  playbook, claus), que són al directori temporal del sistema amb permisos 0700/0600.

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

## Accions

Les accions són scripts o playbooks que llança el professor (no puntuen): posar contrasenya,
instal·lar l'agent de monitoratge... Cada execució queda a `execucions_accio` per alumne, amb
l'estat (`ok`, `changed`, `failed`, `unavailable`, `error`, `timeout`) i la sortida emmascarada.

```bash
php artisan heimdeye:action "Contrasenya de l'usuari jaume" --student=alumne01 --simulate
php artisan heimdeye:action 4 --student=alumne01 --student=alumne02 --host=pve --param=usuari=jaume
php artisan heimdeye:action 4 --queue          # tots els alumnes, a la cua
```

- Un sol `ansible-playbook` per a tots els alumnes seleccionats; el resultat es reparteix per
  alumne segons els equips (els globals compten per a tots).
- `--simulate` executa amb `--check --diff`: no canvia res i mostra què canviaria.
- `--host` limita els tipus d'equip (a l'inventari d'Ansible o als de `# hosts:`); `--param`
  dona valor als paràmetres declarats.
- Si la versió té `checks_verificacio`, després de l'execució real es llancen aquests checks.

### Al panell

Menú Accions → Llista d'accions (`/admin/accions`):

- Filtres per curs (per defecte l'actual), mòdul, executor, nom i estat de la darrera execució
  real per a tots o algun alumne.
- Icona d'estat segons la darrera execució real (les simulacions no compten) de cada alumne
  actiu: verd = tots ok/changed, blau = encara falten alumnes, taronja = ha fallat per a algun,
  vermell = ha fallat per a tots, gris = mai executada.
- Botons Versions, Historial, Editar darrera versió i Llançar (Simular, només Ansible, i Executar).
- Editar no modifica mai una versió: si canvia el codi, els paràmetres, el temps màxim o els
  checks de verificació, es crea la versió següent, amb un comentari opcional del canvi.
  L'executor i el mòdul no es poden canviar.
- Les simulacions no compten per a l'estat ni surten a l'historial: després de Simular es veu
  el resultat a «Darrera simulació» de l'acció.
- A l'editor (nova acció o editar), el botó **Provar** simula o executa el codi del formulari
  sense desar-lo, o executa un check per verificar-ne l'efecte. Els resultats surten a la secció
  Proves i no es desen enlloc. Una versió nova no es pot desar sense haver provat el codi tal com és.
  Les proves s'executen dins la petició web: compte amb els temps màxims de PHP-FPM o del proxy.
- Si una execució falla abans de tenir resultat (p. ex. falta un paràmetre), queda a l'historial
  en estat `error` i l'excepció va al log de Laravel.
- Si hi ha feines a la cua que cap worker no processa, el panell ho avisa a dalt de tot.
- En llançar per a un sol alumne, els paràmetres es mostren calculats amb les seves variables
  (`{{num_taula}}`...) i es poden modificar. Només s'envien els que difereixen del valor per defecte.
