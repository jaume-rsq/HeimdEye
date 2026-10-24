<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Module;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Usuari de prova per entrar en local (HEIMDEYE_AUTH=local): admin@heimdeye.test / password.
 * No es crea fora de l'entorn local.
 */
class DevUserSeeder extends Seeder
{
    public const EMAIL = 'admin@heimdeye.test';

    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $user = User::updateOrCreate(
            ['email' => self::EMAIL],
            ['nom' => 'Usuari de prova', 'password' => 'password', 'rol' => Role::Admin, 'es_actiu' => true],
        );
        foreach (Module::all() as $module) {
            $module->teachers()->syncWithoutDetaching([$user->id]);
        }
    }
}
