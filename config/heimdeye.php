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
    | En tots dos casos només entren els usuaris que ja són a la taula usuaris.
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
    | - ssh_key_path: clau privada a fer servir si la credencial no en té cap desada.
    | - ssh_pubkey_path: clau pública corresponent (per defecte, la privada amb .pub). Arriba als
    |   playbooks com a heimdeye_ssh_pubkey_file.
    */
    'runner' => [
        'wrapper' => env('HEIMDEYE_RUNNER_WRAPPER', ''),
        'ssh_key_path' => env('HEIMDEYE_SSH_KEY_PATH'),
        'ssh_pubkey_path' => env('HEIMDEYE_SSH_PUBKEY_PATH', env('HEIMDEYE_SSH_KEY_PATH') ? env('HEIMDEYE_SSH_KEY_PATH').'.pub' : null),
        'queue' => env('HEIMDEYE_QUEUE', 'checks'),
        'retry_after' => (int) env('HEIMDEYE_RETRY_AFTER', 180),
        'output_limit' => 8192,
        'nightly_at' => env('HEIMDEYE_NIGHTLY_AT', '02:00'),
    ],

    /*
    | Ansible: executable i nom del grup de cada alumne a l'inventari generat.
    */
    'ansible' => [
        'playbook_bin' => env('HEIMDEYE_ANSIBLE_PLAYBOOK', 'ansible-playbook'),
        'student_group' => 'taula{{num_taula}}',
    ],

];
