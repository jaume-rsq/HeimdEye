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

];
