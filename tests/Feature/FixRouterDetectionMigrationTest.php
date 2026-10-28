<?php

namespace Tests\Feature;

use App\Models\Check;
use Database\Seeders\Block05FirewallSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FixRouterDetectionMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_it_adds_a_new_version_to_checks_with_the_old_router_detection(): void
    {
        $this->seed(Block05FirewallSeeder::class);
        $check = Check::where('nom', 'Les regles es recuperen en arrencar')->sole();
        $fixed = $check->currentVersion->codi;
        $check->currentVersion->update(['codi' => str_replace(
            "# Només 255 vol dir que ssh no entra: un Linux respon 127 perquè «:put» no hi existeix.\nif [ \$rc -eq 255 ]",
            'if [ $rc -ne 0 ]',
            $fixed,
        )]);
        $untouched = Check::where('nom', '!=', $check->nom)->withCount('versions')->get()->pluck('versions_count', 'id');

        (require database_path('migrations/2026_10_28_000005_fix_router_detection.php'))->up();

        $this->assertSame(2, $check->versions()->count());
        $this->assertSame($fixed, $check->fresh()->currentVersion->codi);
        $this->assertSame($untouched->all(), Check::where('nom', '!=', $check->nom)->withCount('versions')->get()->pluck('versions_count', 'id')->all());
    }
}
