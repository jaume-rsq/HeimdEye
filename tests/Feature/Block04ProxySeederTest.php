<?php

namespace Tests\Feature;

use App\Enums\CheckKind;
use App\Enums\Executor;
use App\Models\Block;
use App\Models\Check;
use App\Models\Module;
use App\Runner\CodeValidator;
use Database\Seeders\Block04ProxySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class Block04ProxySeederTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Module $module;

    protected function setUp(): void
    {
        parent::setUp();
        $base = Module::where('codi', '9991')->sole();
        $this->module = $base->replicate();
        $this->module->codi = '0378';
        $this->module->save();
        $this->seed(Block04ProxySeeder::class);
    }

    public function test_it_creates_block_4_with_its_two_tickets_and_checks(): void
    {
        $this->seed(Block04ProxySeeder::class); // idempotent: no duplica res

        $block = Block::where('modul_id', $this->module->id)->where('numero', 4)->sole();
        $this->assertSame('Servidors proxy', $block->titol);
        $this->assertSame(['Tiquet 1', 'Tiquet 2'], $block->tasks()->orderBy('posicio')->pluck('titol')->all());

        $t1 = $block->tasks()->where('titol', 'Tiquet 1')->sole()->checks;
        $t2 = $block->tasks()->where('titol', 'Tiquet 2')->sole()->checks;
        $this->assertCount(12, $t1);
        $this->assertCount(5, $t2);
        foreach ($t1->concat($t2) as $check) {
            $this->assertSame(Executor::Agent, $check->executor, $check->nom);
            $this->assertSame(CheckKind::Required, $check->tipus, $check->nom);
            $this->assertSame(1, $check->punts, $check->nom);
            $this->assertTrue(filled($check->descripcio) && filled($check->info), $check->nom);
            $this->assertSame(1, $check->versions()->count(), $check->nom);
        }
    }

    public function test_the_code_passes_the_save_rules_and_bash_syntax(): void
    {
        $validator = app(CodeValidator::class);

        foreach (Check::whereIn('tasca_id', Block::where('numero', 4)->sole()->tasks()->pluck('id'))->get() as $check) {
            $code = $check->currentVersion->codi;
            $validator->validate($this->module, $check->executor, $code);
            $this->assertStringStartsWith("# hosts: aws\n", $code, $check->nom);

            $process = new Process(['bash', '-n']);
            $process->setInput($code)->run();
            $this->assertTrue($process->isSuccessful(), "{$check->nom}: {$process->getErrorOutput()}");
        }
    }

    public function test_a_changed_code_adds_a_version_and_keeps_the_rest(): void
    {
        $check = Check::where('nom', 'Q6: El HTTP redirigeix a HTTPS')->sole();
        $check->versions()->update(['codi' => "# hosts: aws\nexit 0"]);
        $check->update(['punts' => 3]);

        $this->seed(Block04ProxySeeder::class);

        $check->refresh();
        $this->assertSame(2, $check->versions()->count());
        $this->assertSame(2, $check->currentVersion->versio);
        $this->assertSame(1, $check->punts, 'el seeder torna a posar els punts previstos');
        $this->assertSame(17, Check::whereIn('tasca_id', Block::where('numero', 4)->sole()->tasks()->pluck('id'))->count());
    }
}
