<?php

namespace Tests\Feature;

use App\Enums\CheckKind;
use App\Models\Block;
use App\Models\Check;
use App\Models\Module;
use App\Models\Task;
use App\Runner\CodeValidator;
use Database\Seeders\Tiquet1TecnoServeisSeeder;
use Database\Seeders\Tiquet2TecnoServeisSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class Tiquet1TecnoServeisSeederTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Module $module;

    private Block $block;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $base = Module::where('codi', '9991')->sole();
        $this->module = $base->replicate();
        $this->module->codi = '0378';
        $this->module->save();
        $this->block = $this->module->blocks()->create(['numero' => 3, 'titol' => 'Bloc 3', 'posicio' => 3]);
        $this->task = $this->block->tasks()->create(['titol' => 'Tiquet 1', 'enunciat' => 'T1', 'posicio' => 1]);
        $this->block->tasks()->create(['titol' => 'Tiquet 2', 'enunciat' => 'T2', 'posicio' => 2]);
    }

    public function test_it_replaces_the_checks_of_ticket_1_only(): void
    {
        $this->seed(Tiquet2TecnoServeisSeeder::class);
        $this->task->checks()->create(['nom' => 'Vell', 'executor' => 'agent']);

        $this->seed(Tiquet1TecnoServeisSeeder::class);
        $this->seed(Tiquet1TecnoServeisSeeder::class); // torna a començar

        $checks = $this->task->checks()->get();
        $this->assertCount(15, $checks);
        $this->assertFalse($checks->contains('nom', 'Vell'));
        $this->assertTrue($checks->every(fn (Check $c) => $c->tipus === CheckKind::Required && $c->punts === 1
            && filled($c->descripcio) && filled($c->info) && $c->isActiveOn() && $c->versions()->count() === 1));
        $this->assertSame(21, $this->block->tasks()->where('titol', 'Tiquet 2')->sole()->checks()->count());
        $this->assertSame('T1', $this->task->fresh()->enunciat);
    }

    public function test_the_code_passes_the_save_rules_and_bash_syntax(): void
    {
        $this->seed(Tiquet1TecnoServeisSeeder::class);
        $validator = app(CodeValidator::class);

        foreach ($this->task->checks()->get() as $check) {
            $code = $check->currentVersion->codi;
            $validator->validate($this->module, $check->executor, $code);
            $this->assertStringStartsWith("# hosts: aws\n", $code, $check->nom);

            $process = new Process(['bash', '-n']);
            $process->setInput($code)->run();
            $this->assertTrue($process->isSuccessful(), "{$check->nom}: {$process->getErrorOutput()}");
        }
    }
}
