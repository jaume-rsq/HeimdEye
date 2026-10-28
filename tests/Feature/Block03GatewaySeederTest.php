<?php

namespace Tests\Feature;

use App\Enums\CheckKind;
use App\Models\Block;
use App\Models\Check;
use App\Runner\CodeValidator;
use App\Runner\Params;
use Database\Seeders\Block03GatewaySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class Block03GatewaySeederTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(Block03GatewaySeeder::class);
    }

    public function test_block_3_has_its_tasks_and_checks(): void
    {
        $this->seed(Block03GatewaySeeder::class);
        $block = Block::where('numero', 3)->sole();

        $this->assertSame(4, $block->tasks()->count());
        $checks = $this->checks();
        $this->assertCount(10, $checks);
        $this->assertSame(14, $checks->where('tipus', CheckKind::Required)->sum('punts'));
        $this->assertSame(2, $checks->where('tipus', CheckKind::Optional)->sum('punts'));
        $this->assertTrue($checks->every(fn (Check $c) => filled($c->descripcio) && filled($c->info) && $c->versions()->count() === 1));
    }

    public function test_block_3_code_passes_the_save_rules(): void
    {
        $validator = app(CodeValidator::class);
        $module = Block::where('numero', 3)->sole()->module;

        foreach ($this->checks() as $check) {
            $version = $check->currentVersion;
            $validator->validate($module, $check->executor, $version->codi);
            Params::values($version->parametres);

            $process = new Process(['bash', '-n']);
            $process->setInput($version->codi)->run();
            $this->assertTrue($process->isSuccessful(), "{$check->nom}: {$process->getErrorOutput()}");
        }
    }

    /** @return Collection<int, Check> */
    private function checks()
    {
        return Check::whereIn('tasca_id', Block::where('numero', 3)->sole()->tasks()->pluck('id'))->get();
    }
}
