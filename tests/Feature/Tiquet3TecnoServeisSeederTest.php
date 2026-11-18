<?php

namespace Tests\Feature;

use App\Enums\CheckKind;
use App\Models\Action;
use App\Models\Block;
use App\Models\Check;
use App\Models\Module;
use App\Models\Task;
use App\Runner\CodeValidator;
use App\Runner\Params;
use Carbon\Carbon;
use Database\Seeders\Tiquet2TecnoServeisSeeder;
use Database\Seeders\Tiquet3TecnoServeisSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class Tiquet3TecnoServeisSeederTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Module $module;

    private Block $block;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        // El professor crea el mòdul 0378, el bloc 3 i les tasques.
        $base = Module::where('codi', '9991')->sole();
        $this->module = $base->replicate();
        $this->module->codi = '0378';
        $this->module->save();
        $this->block = $this->module->blocks()->create(['numero' => 3, 'titol' => 'Bloc 3', 'posicio' => 3]);
        $this->block->tasks()->create(['titol' => 'Tiquet 1', 'enunciat' => 'T1', 'posicio' => 1]);
        $this->block->tasks()->create(['titol' => 'Tiquet 2', 'enunciat' => 'T2', 'posicio' => 2]);
        $this->task = $this->block->tasks()->create(['titol' => 'Tiquet 3', 'enunciat' => 'El del professor', 'posicio' => 3]);
    }

    public function test_it_creates_checks_active_only_this_week_and_actions(): void
    {
        // Es queden: el check del T1, el del T2, l'acció del T2 i una acció pròpia. Se'n va el que el seeder crea.
        $t1 = $this->block->tasks()->where('titol', 'Tiquet 1')->sole()->checks()->create(['nom' => 'Del T1', 'executor' => 'agent']);
        $this->seed(Tiquet2TecnoServeisSeeder::class);
        $t2checks = $this->block->tasks()->where('titol', 'Tiquet 2')->sole()->checks()->count();
        $own = $this->module->actions()->create(['nom' => 'Una altra', 'bloc_id' => $this->block->id, 'executor' => 'agent']);
        $this->task->checks()->create(['nom' => 'Vell', 'executor' => 'agent']);

        $this->seed(Tiquet3TecnoServeisSeeder::class);
        $this->seed(Tiquet3TecnoServeisSeeder::class); // es pot tornar a executar: torna a començar

        $this->assertModelExists($t1);
        $this->assertModelExists($own);
        $this->assertSame($t2checks, $this->block->tasks()->where('titol', 'Tiquet 2')->sole()->checks()->count());
        $this->assertSame('El del professor', $this->task->fresh()->enunciat);

        $checks = $this->task->checks()->get();
        $this->assertCount(19, $checks);
        $required = $checks->where('tipus', CheckKind::Required);
        $this->assertCount(18, $required);
        $this->assertSame(18, $required->sum('punts'));
        $this->assertTrue($required->every(fn (Check $c) => filled($c->info)));
        $this->assertTrue($checks->every(fn (Check $c) => filled($c->descripcio) && $c->versions()->count() === 1 && $c->es_actiu === null));

        // Actius la setmana del 16 al 22 de novembre de 2026, i només aquesta.
        $this->assertTrue($checks->every(fn (Check $c) => $c->isActiveOn(Carbon::parse('2026-11-16')) && $c->isActiveOn(Carbon::parse('2026-11-22'))));
        $this->assertTrue($checks->every(fn (Check $c) => ! $c->isActiveOn(Carbon::parse('2026-11-15')) && ! $c->isActiveOn(Carbon::parse('2026-11-23'))));

        $this->assertSame(0, Action::where('bloc_id', $this->block->id)->where('nom', 'like', 'T3:%')->count());
    }

    public function test_all_code_passes_the_save_rules_and_bash_syntax(): void
    {
        $this->seed(Tiquet3TecnoServeisSeeder::class);
        $validator = app(CodeValidator::class);

        $codes = $this->task->checks()->get()->map(fn (Check $c) => [$c->nom, $c->executor, $c->currentVersion->codi, $c->currentVersion->parametres]);

        foreach ($codes as [$name, $executor, $code, $params]) {
            $validator->validate($this->module, $executor, $code);
            Params::values($params);
            $this->assertStringStartsWith("# hosts: aws\n", $code, $name);

            $process = new Process(['bash', '-n']);
            $process->setInput($code)->run();
            $this->assertTrue($process->isSuccessful(), "{$name}: {$process->getErrorOutput()}");
        }
    }

    public function test_the_code_has_no_secrets_or_resolved_placeholders(): void
    {
        $code = implode("\n", Tiquet3TecnoServeisSeeder::codes()['checks']);

        $this->assertStringNotContainsString('@NOM@', $code);
        $this->assertStringNotContainsString('@VAR@', $code);
        $this->assertDoesNotMatchRegularExpression('/restrict-http-upgrade-path-prefix\s+\S/', $code);
        $this->assertDoesNotMatchRegularExpression('/PrivateKey\s*=\s*[A-Za-z0-9+\/]{43}=/', $code);
    }

    public function test_it_needs_the_module_and_the_block(): void
    {
        $this->block->delete();
        $this->expectExceptionMessage('El mòdul 0378 no té el bloc 3');
        $this->seed(Tiquet3TecnoServeisSeeder::class);
    }
}
