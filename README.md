# HeimdEye
Aplicació de monitoratge automàtic de tasques d'alumnes.

Cada tasca es verifica amb checks binaris que el servidor executa cada nit (o a demanda)
contra els equips dels alumnes. El disseny complet és al document de disseny del projecte.

## Estat

Fase 1: base de dades, models i seeders. Encara no hi ha vistes ni runner.

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
dades personals. Copia l'exemple i omple-hi les dades reals:

```bash
cp database/seeders/data/students.example.csv database/seeders/data/students.csv
```

Columnes: `table_no,username,full_name,email,x,router_ip,aula_net`. Si el fitxer no existeix,
es carreguen 10 alumnes ficticis.

## Model de dades

Curs (`academic_years`) → Cicle (`cycles`) → Mòdul (`modules`) → Bloc (`blocks`) → Tasca (`tasks`) → Check (`checks`).

- `modules`: oficial o lliure (`combines` llista els mòduls oficials que agrupa), amb les
  variables derivades (`derived_vars`, plantilles `{{x}}`) i la credencial per defecte.
- `students` pertanyen a un curs i un cicle i es matriculen a mòduls (`enrollments`).
  Els professors s'assignen a mòduls (`module_teacher`).
- `checks`: tipus obligatori/opcional, punts, `active` (null = segueix `active_from`/`active_to`),
  deadline i bonus de puntualitat en %. El codi és a `check_versions` (la vigent és la de número més alt).
- `check_runs`: log de cada execució. `check_student`: estat actual per (check, alumne), amb
  l'estat del professor (preval sobre l'automàtic), comentari i primera data d'assoliment.

### Contracte d'un check

L'script acaba amb codi 0 si el check s'assoleix; el que escriu a stdout és el missatge.
Les variables de l'alumne arriben com a entorn `HE_*` (`HE_NS1`, `HE_SUBDOMAIN`, `HE_X`...).
Vegeu `App\Support\VariableResolver` i el bloc d'exemple a `database/seeders/Block08DnsSeeder.php`.
