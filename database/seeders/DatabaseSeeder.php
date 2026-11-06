<?php

namespace Database\Seeders;

use App\Support\Library;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            AcademicStructureSeeder::class,
            StudentSeeder::class,
            HostsSeeder::class,
            Block02ProxmoxSeeder::class,
            DevUserSeeder::class,
        ]);

        // La biblioteca de checks i accions (mòdul 0000), al final: no canvia els ids de la resta.
        Library::ensure();
    }
}
