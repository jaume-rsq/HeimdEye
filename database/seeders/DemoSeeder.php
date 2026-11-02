<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * BBDD de la demo: el tutorial (alumnes ficticis) i l'administrador de la configuració, i amb el
 * laboratori de xarxa (HEIMDEYE_LAB=true, lab/lab.sh) el mòdul de la demo SMIX2.
 * A la demo pública, els visitants entren amb el botó de la demo (App\Support\Demo).
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => config('heimdeye.admin.email')],
            ['nom' => config('heimdeye.admin.name'), 'rol' => Role::Admin, 'es_actiu' => true],
        );

        $this->call(TutorialSeeder::class);

        if (config('heimdeye.lab.enabled')) {
            $this->call(Smix2LabSeeder::class);
        }
    }
}
