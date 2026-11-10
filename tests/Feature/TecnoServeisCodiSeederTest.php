<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Block;
use App\Models\Check;
use App\Models\Module;
use App\Models\Task;
use Database\Seeders\TecnoServeisCodiSeeder;
use Database\Seeders\Tiquet1TecnoServeisSeeder;
use Database\Seeders\Tiquet2TecnoServeisSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TecnoServeisCodiSeederTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Block $block;

    private Task $t1;

    private Task $t2;

    protected function setUp(): void
    {
        parent::setUp();
        $base = Module::where('codi', '9991')->sole();
        $module = $base->replicate();
        $module->codi = '0378';
        $module->save();
        $this->block = $module->blocks()->create(['numero' => 3, 'titol' => 'Bloc 3', 'posicio' => 3]);
        $this->t1 = $this->block->tasks()->create(['titol' => 'Tiquet 1', 'posicio' => 1]);
        $this->t2 = $this->block->tasks()->create(['titol' => 'Tiquet 2', 'posicio' => 2]);
    }

    public function test_each_code_carries_only_the_functions_it_uses(): void
    {
        $t2 = Tiquet2TecnoServeisSeeder::codes();
        $all = Tiquet1TecnoServeisSeeder::codes() + $t2['checks'] + $t2['accions'];

        foreach ($all as $name => $code) {
            $this->assertStringStartsWith("# hosts: aws\n", $code, $name);
            preg_match_all('/^(\w+)\(\) \{/m', $code, $functions);
            foreach ($functions[1] as $function) {
                // La definició i, com a mínim, una crida.
                $this->assertGreaterThanOrEqual(2, preg_match_all('/(?<![\w-])'.$function.'(?![\w-])/', $code), "{$name}: {$function}() no es fa servir");
            }
            foreach (['PORTS_ESCANEIG', 'LOC_DNAT'] as $variable) {
                $this->assertSame(str_contains($code, "\n{$variable}="), str_contains($code, '$'.$variable), "{$name}: {$variable}");
            }
        }

        $p6 = Tiquet1TecnoServeisSeeder::codes()['P6: Cap Security Group amb el 22'];
        $this->assertStringNotContainsString('sg_permet', $p6);
        $this->assertLessThan(15, substr_count($p6, "\n"));
    }

    public function test_it_adds_a_version_only_where_the_code_changed(): void
    {
        $this->seed(Tiquet2TecnoServeisSeeder::class);
        $this->seed(Tiquet1TecnoServeisSeeder::class);
        // Com estava abans: el codi amb tot el preàmbul.
        $checks = Check::whereIn('tasca_id', [$this->t1->id, $this->t2->id])->with('currentVersion')->get();
        $actions = Action::where('bloc_id', $this->block->id)->with('currentVersion')->get();
        foreach ([...$checks, ...$actions] as $record) {
            $record->currentVersion->update(['codi' => $record->currentVersion->codi."\n# vell"]);
        }
        $untouched = $checks->first();
        $untouched->currentVersion->update(['codi' => Tiquet1TecnoServeisSeeder::codes()[$untouched->nom] ?? Tiquet2TecnoServeisSeeder::codes()['checks'][$untouched->nom]]);
        $other = $this->block->module->actions()->create(['nom' => 'Una altra', 'bloc_id' => $this->block->id, 'executor' => 'agent']);
        $other->versions()->create(['versio' => 1, 'codi' => "# hosts: aws\ntrue\n"]);

        $this->seed(TecnoServeisCodiSeeder::class);
        $this->seed(TecnoServeisCodiSeeder::class); // ja està al dia: no fa res

        $this->assertSame(1, $untouched->versions()->count());
        $this->assertSame(1, $other->versions()->count());
        foreach ([...$checks->except($untouched->id), ...$actions] as $record) {
            $this->assertSame(2, $record->versions()->count(), $record->nom);
            $new = $record->fresh()->currentVersion;
            $this->assertSame(TecnoServeisCodiSeeder::COMMENT, $new->comentari);
            $this->assertStringNotContainsString('# vell', $new->codi);
            $this->assertSame($record->currentVersion->temps_max_seg, $new->temps_max_seg);
            $this->assertSame($record->currentVersion->parametres, $new->parametres);
        }
        $all = $actions->firstWhere('nom', 'T2: muntar tot el tiquet 2');
        $this->assertSame($all->currentVersion->checks_verificacio, $all->fresh()->currentVersion->checks_verificacio);
        $this->assertCount(5, $all->fresh()->currentVersion->checks_verificacio);
    }
}
