<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * BBDD de la demo pública: només el tutorial (alumnes ficticis) i l'administrador de la configuració.
 * Els visitants entren amb el botó de la demo (App\Support\Demo).
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
    }
}
