# HeimdEye
Aplicació de monitoratge automàtic de tasques d'alumnes.

Cada tasca es verifica amb checks binaris que el servidor executa cada nit (o a demanda)
contra els equips dels alumnes. El disseny complet és al document de disseny del projecte.

## Estat

Fase 3: base de dades, seeders, runner de checks (executors `local`, `ssh` i `ansible`) i accions
del professor. Encara no hi ha vistes.

## Posada en marxa (desenvolupament)

Requisits: PHP 8.3+, Composer i PostgreSQL (també funciona amb MariaDB o SQLite).

```bash
composer install
cp .env.example .env
php artisan key:generate
# Edita .env: credencials de la BBDD, HEIMDEYE_ADMIN_NAME i HEIMDEYE_ADMIN_EMAIL
php artisan migrate --seed
php artisan test
```

### Alumnes

El seeder llegeix `database/seeders/data/students.csv`, que **no es versiona** perquè conté
dades personals (el repositori és públic). Hi pots enganxar la llista de l'aula tal qual,
separada per tabuladors o per comes, amb aquesta capçalera:

```
Xarxa	Taula	Alumne	eMail	alum
192.168.20.32/28	Taula01	Cognoms, Nom	usuari@iesjulioantonio.cat	alum32
```

L'usuari és la part del correu abans de l'`@`, i X = 20 + número de taula. Si el fitxer no
existeix, es carreguen 10 alumnes ficticis (`students.example.csv`).

Variables derivades del mòdul 9991: `lan` = 172.X.0.0/22, `dmz` = 10.X.0.0/22,
`pve_ip` = tercera adreça útil de la xarxa d'aula, `router_ip` = darrera adreça útil.
Les plantilles admeten `{{ aula_net | host(3) }}` i `{{ aula_net | host(-1) }}`.

## Model de dades

Curs (`cursos`) → Cicle (`cicles`) → Mòdul (`moduls`) → Bloc (`blocs`) → Tasca (`tasques`) → Check (`checks`).
Els noms de taules i camps són en català (sense accents); les classes PHP es mantenen en anglès
(`Module`, `Student`...) i cada model declara la seva `$table`.

- `moduls`: oficial o lliure (`combina` llista els mòduls oficials que agrupa), amb les
  variables derivades (`variables_derivades`, plantilles `{{x}}`) i la credencial per defecte.
- `alumnes` pertanyen a un curs i un cicle i es matriculen a mòduls (`modul_alumne`).
  Els professors s'assignen a mòduls (`modul_professor`).
- `checks`: tipus obligatori/opcional, punts, `es_actiu` (null = segueix `actiu_des`/`actiu_fins`),
  `data_limit` i bonus de puntualitat en %. El codi és a `versions_check` (la vigent és la de número més alt).
- `execucions`: un lot per nocturna, execució manual o acció. `execucions_check`: log de cada
  execució. `check_alumne`: estat actual per (check, alumne), amb l'estat del professor (preval
  sobre l'automàtic), comentari i primera data d'assoliment.

### Contracte d'un check

L'script acaba amb codi 0 si el check s'assoleix; el que escriu a stdout és el missatge.
Les variables de l'alumne arriben com a entorn `HE_*` (`HE_NS1`, `HE_SUBDOMAIN`, `HE_X`...).
Els checks que fan `ssh` des del servidor reben també `HE_SSH_USER` i `HE_SSH_KEY` (credencial del mòdul).
Vegeu `App\Support\VariableResolver` i els checks inicials a `database/seeders/Block02ProxmoxSeeder.php`.

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
- Clau SSH: la de la credencial del mòdul si en té una de desada; si no, `HEIMDEYE_SSH_KEY_PATH`;
  si tampoc, la clau per defecte de l'usuari que executa el worker.
- Aïllament: `HEIMDEYE_RUNNER_WRAPPER` embolcalla cada execució, per exemple
  `systemd-run --uid=heimdeye-runner --pipe --wait --collect --quiet -p MemoryMax=256M -p CPUQuota=50%`.
  Amb `systemd-run` s'hi afegeixen `RuntimeMaxSec` i les variables (`--setenv`). L'usuari del
  sandbox ha de poder llegir la clau SSH i els fitxers temporals que crea el runner (inventari,
  playbook, claus), que són al directori temporal del sistema amb permisos 0700/0600.

## Equips, inventari Ansible i secrets

Cada mòdul defineix els seus **tipus d'equip** (`tipus_equip`): grup Ansible, nom del host i
adreça (plantilles amb variables de l'alumne), variables de connexió i credencial. Els de
tipus `student` en generen un per alumne; els `global` (com el Zabbix) un de sol.
A partir d'aquí es genera l'inventari en JSON, equivalent a `smix2.ini`: grups per tipus
(`pve`, `vm_router`, `vm_dns_bind`...), un grup per alumne (`taula01`...) amb les seves
variables, i `alumnes`. Vegeu `database/seeders/HostsSeeder.php`.

Els **secrets** del mòdul (`secrets`, xifrats amb `APP_KEY`) fan de vault: un playbook o
script rep només els que anomena (com a variable Ansible o `HE_SECRET_*`), i a la sortida
desada apareixen com a `********`. Els valors s'omplen des de l'administració, mai al repositori.

Els checks també poden ser playbooks (`executor = ansible`); si un check `ssh` porta
`configuracio.host_type`, es connecta a l'adreça i amb la credencial d'aquell tipus d'equip.

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
- `--host` limita els tipus d'equip a l'inventari; `--param` dona valor als paràmetres declarats.
- Si l'acció té `checks_verificacio`, després de l'execució real es llancen aquests checks.
