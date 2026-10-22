<?php

return [

    /*
    | Primer usuari administrador que crea el seeder. El login real es fa amb
    | Cloudflare Access, que identifica l'usuari pel correu.
    */
    'admin' => [
        'name' => env('HEIMDEYE_ADMIN_NAME', 'Administrador'),
        'email' => env('HEIMDEYE_ADMIN_EMAIL', 'admin@example.com'),
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
    */
    'runner' => [
        'wrapper' => env('HEIMDEYE_RUNNER_WRAPPER', ''),
        'ssh_key_path' => env('HEIMDEYE_SSH_KEY_PATH'),
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
