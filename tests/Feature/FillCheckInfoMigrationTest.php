<?php

namespace Tests\Feature;

use App\Models\Check;
use Database\Seeders\Block05FirewallSeeder;
use Database\Seeders\Block06DnsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FillCheckInfoMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_it_fills_the_info_of_existing_checks_without_overwriting_it(): void
    {
        $this->seed(Block05FirewallSeeder::class);
        $this->seed(Block06DnsSeeder::class);
        Check::query()->update(['info' => null]);
        $edited = Check::where('nom', 'SSH a ns1')->sole();
        $edited->update(['info' => 'Text del professor']);

        (require database_path('migrations/2026_10_27_000002_fill_check_info.php'))->up();

        $info = require database_path('seeders/data/check_info.php');
        $this->assertSame(count($info[5]) + count($info[6]), Check::whereNotNull('info')->count());
        $this->assertSame('Text del professor', $edited->fresh()->info);
        $this->assertSame($info[5]['RouterOS actualitzat'], Check::where('nom', 'RouterOS actualitzat')->sole()->info);
    }
}
