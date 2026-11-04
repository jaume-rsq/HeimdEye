<?php

return [

    /*
    | Primer usuari administrador que crea el seeder. En producció entra amb
    | Cloudflare Access, que l'identifica pel correu.
    */
    'admin' => [
        'name' => env('HEIMDEYE_ADMIN_NAME', 'Administrador'),
        'email' => env('HEIMDEYE_ADMIN_EMAIL', 'admin@example.com'),
    ],

    /*
    | Autenticació:
    | - local: formulari de correu i contrasenya (desenvolupament).
    | - cloudflare: l'app és darrere Cloudflare Access (login de Google Workspace); es valida
    |   el JWT de la capçalera Cf-Access-Jwt-Assertion i l'usuari es busca pel correu.
    | - plantilla: còpia d'un sol professor (p. ex. un escriptori d'IsardVDI): s'entra directament
    |   com l'administrador (admin.email), sense contrasenya. Només si l'app no és accessible de fora.
    | En tots els casos només entren els usuaris que ja són a la taula usuaris.
    */
    'auth' => [
        'mode' => env('HEIMDEYE_AUTH', 'local'),
        'cloudflare' => [
            'team_domain' => env('CF_ACCESS_TEAM_DOMAIN'), // equip.cloudflareaccess.com
            'aud' => env('CF_ACCESS_AUD'),
        ],
    ],

    /*
    | Proxies de confiança (IPs separades per comes), p. ex. la màquina del cloudflared. Calen
    | perquè Laravel sàpiga que la petició original era HTTPS i quina IP tenia el client.
    */
    'trusted_proxies' => env('HEIMDEYE_TRUSTED_PROXIES'),

    /*
    | Desplegament: minuts abans de la ronda nocturna en què ja no es desplega.
    */
    'deploy' => [
        'quiet_before_nightly_min' => 20,
    ],

    /*
    | Fitxer CSV amb els alumnes per al seeder. No es versiona (dades personals);
    | si no existeix, s'usa l'exemple amb dades fictícies.
    */
    'students_csv' => env('HEIMDEYE_STUDENTS_CSV', database_path('seeders/data/students.csv')),

    /*
    | Execució dels checks.
    | - wrapper: ordre que embolcalla cada execució, p. ex.
    |   "systemd-run --uid=heimdeye-runner --pipe --wait --collect --quiet -p MemoryMax=256M -p CPUQuota=50%"
    |   (amb systemd-run s'hi afegeixen RuntimeMaxSec i les variables amb --setenv). Buit = directe.
    |   A la demo pública, lab/heimdeye-sandbox: s'executa al contenidor LXC de proves (lab/lab.sh).
    | Les claus SSH són secrets (Inventari → Secrets comuns): heimdeye:import-key en carrega una.
    */
    'runner' => [
        'wrapper' => env('HEIMDEYE_RUNNER_WRAPPER', ''),
        'queue' => env('HEIMDEYE_QUEUE', 'checks'),
        'retry_after' => (int) env('HEIMDEYE_RETRY_AFTER', 180),
        'output_limit' => 8192,
        'nightly_at' => env('HEIMDEYE_NIGHTLY_AT', '02:00'),
    ],

    /*
    | Mode demo (App\Support\Demo): instància pública on qualsevol entra com a professor visitant.
    | - max_timeout: segons màxims de qualsevol execució, sigui quin sigui el temps de la versió.
    | - runs_per_hour: execucions (per alumne) que pot llançar cada visitant en una hora.
    | - visitor_hours: hores d'inactivitat després de les quals heimdeye:demo-cleanup esborra el
    |   visitant i els checks i les accions que ha creat.
    | - student_key: ruta de la clau dels alumnes ficticis, al contenidor on s'executa el codi (a la
    |   demo pública, només al de proves). TutorialSeeder la desa al secret ssh_demo.
    | - url: la demo pública, enllaçada al menú Ajuda de les altres instàncies (buit: no s'enllaça).
    */
    'demo' => [
        'enabled' => (bool) env('HEIMDEYE_DEMO', false),
        'max_timeout' => (int) env('HEIMDEYE_DEMO_MAX_TIMEOUT', 30),
        'runs_per_hour' => (int) env('HEIMDEYE_DEMO_RUNS_PER_HOUR', 60),
        'visitor_hours' => (int) env('HEIMDEYE_DEMO_VISITOR_HOURS', 2),
        'student_key' => env('HEIMDEYE_DEMO_STUDENT_KEY'),
        'url' => env('HEIMDEYE_DEMO_URL', 'https://heimdeye-demo.julioantonio.cat/'),
    ],

    /*
    | Laboratori de xarxa de la demo SMIX2 (lab/lab.sh, docs/demo-smix2.md): router, client i
    | servidor de la DMZ per alumne, en contenidors LXC. Si està actiu, DemoSeeder hi afegeix el mòdul.
    | - students: alumnes ficticis (1 a 4), els mateixos que crea lab/lab.sh.
    | - key: ruta de la clau privada amb què HeimdEye entra als equips del laboratori (secret ssh_lab).
    */
    'lab' => [
        'enabled' => (bool) env('HEIMDEYE_LAB', false),
        'students' => (int) env('HEIMDEYE_LAB_ALUMNES', 2),
        'key' => env('HEIMDEYE_LAB_KEY'),
    ],

    /*
    | Agent de les instàncies dels alumnes (App\Http\Controllers\Api\AgentController).
    | - expiry_seconds: segons per defecte que una feina espera que l'agent la reculli abans de
    |   caducar (es pot afinar per acció amb accions.caducitat_agent_seg).
    | - inactive_after_seconds: sense batec (long poll), una instància es marca inactiva i se li
    |   cancel·len les feines en curs.
    | - poll_hold_seconds: temps que el servidor manté oberta la petició de long poll esperant feina.
    | Consola interactiva (App\Http\Controllers\Api\AgentConsoleController):
    | - console_pickup_seconds: segons que una sessió espera que l'agent la reculli abans de caducar.
    | - console_idle_seconds: sense activitat (entrada ni sortida), la sessió es tanca sola.
    | - console_hold_seconds: temps que el servidor manté oberta la petició d'entrada (long poll).
    */
    'agent' => [
        'expiry_seconds' => (int) env('HEIMDEYE_AGENT_EXPIRY', 900),
        'inactive_after_seconds' => (int) env('HEIMDEYE_AGENT_INACTIVE_AFTER', 300),
        'poll_hold_seconds' => (int) env('HEIMDEYE_AGENT_POLL_HOLD', 25),
        'console_pickup_seconds' => (int) env('HEIMDEYE_AGENT_CONSOLE_PICKUP', 120),
        'console_idle_seconds' => (int) env('HEIMDEYE_AGENT_CONSOLE_IDLE', 600),
        'console_hold_seconds' => (int) env('HEIMDEYE_AGENT_CONSOLE_HOLD', 20),
    ],

    /*
    | Ansible: executable i nom del grup de cada alumne a l'inventari generat.
    */
    'ansible' => [
        'playbook_bin' => env('HEIMDEYE_ANSIBLE_PLAYBOOK', 'ansible-playbook'),
        'student_group' => 'taula{{num_taula}}',
    ],

];
