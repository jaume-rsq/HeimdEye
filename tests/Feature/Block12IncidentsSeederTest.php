<?php

namespace Tests\Feature;

use App\Enums\CheckKind;
use App\Enums\Executor;
use App\Models\Action;
use App\Models\Block;
use App\Models\Check;
use App\Models\Incident;
use App\Models\IncidentStudent;
use App\Models\Module;
use App\Models\Student;
use App\Runner\CodeValidator;
use App\Runner\Incidents\IncidentLauncher;
use App\Runner\Params;
use Database\Seeders\Block12CorreuSeeder;
use Database\Seeders\Block12IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class Block12IncidentsSeederTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(Block12CorreuSeeder::class);
        $this->seed(Block12IncidentsSeeder::class);
    }

    public function test_it_creates_five_cases_with_six_variants_each(): void
    {
        $this->seed(Block12IncidentsSeeder::class); // es pot repetir mentre no s'hagi llançat

        $block = Block::where('numero', 12)->sole();
        $task = $block->tasks()->where('titol', Block12IncidentsSeeder::TASK)->sole();
        $incidents = Incident::where('tasca_id', $task->id)->orderBy('posicio')->get();

        $this->assertSame(15, $block->tasks()->count());
        $this->assertSame(['Servidor de correu', 'DNS', 'Router', 'Proxmox', 'Seguretat'], $incidents->pluck('nom')->all());
        // Cada cas té el text de la seva categoria; les notes no parlen de l'ordre i porten la causa de cada variant.
        $this->assertCount(5, $incidents->pluck('simptoma')->unique());
        $this->assertTrue($incidents->every(fn (Incident $i) => $i->minuts_verificacio === 2 && blank($i->equip) && filled($i->notes)));
        $this->assertTrue($incidents->every(fn (Incident $i) => ! str_contains($i->notes, "S'injecta")));
        $this->assertStringContainsString('SRV-1: la zona falta a mydestination.', $incidents->first()->notes);

        foreach ($incidents as $incident) {
            $options = $incident->options;
            $this->assertCount(6, $options);
            $this->assertSame(['1', '2', '3', '4', '5', '6'], $options->map(fn ($o) => $o->parametres['AVARIA'])->all());
            $this->assertTrue($options->every(fn ($o) => filled($o->simptoma) && $o->check_id && $o->accio_reparacio_id));
            $this->assertCount(6, $options->pluck('check_id')->unique());
            // Ni l'opció ni el check de resolució delaten la causa: només la categoria i la variant.
            $this->assertTrue($options->every(fn ($o) => preg_match('/^[A-Z]{3}-[1-6]$/', $o->nom) === 1));
            $this->assertTrue($options->every(fn ($o) => $o->check->nom === 'Incidència '.$o->nom));
            $this->assertCount(1, $options->pluck('accio_reparacio_id')->unique());
            $this->assertCount(1, $options->pluck('accio_id')->unique());
            $this->assertTrue($options->first()->action->es_incidencia);
            $this->assertFalse($options->first()->repairAction->es_incidencia);
        }
        $this->assertSame(30, $task->checks()->count());
        $this->assertTrue($task->checks()->get()->every(fn (Check $c) => $c->tipus === CheckKind::Incident && $c->punts === 0 && $c->es_actiu === false));
        $this->assertSame(10, Action::where('tasca_id', $task->id)->count());
        // No llança res.
        $this->assertSame(0, IncidentStudent::count());
    }

    public function test_all_code_passes_the_save_rules_and_bash_syntax(): void
    {
        $module = Module::where('codi', '9991')->sole();
        $validator = app(CodeValidator::class);

        foreach ($this->codes() as [$name, $executor, $code, $params]) {
            $validator->validate($module, $executor, $code);
            Params::values($params);
            $this->assertBash($code, $name);
            $this->assertSame($executor === Executor::Ssh, str_starts_with($code, "# hosts: pve\n"), $name);
            // El codi que s'envia dins de l'LXC i de ns1 també ha de ser bash correcte.
            preg_match_all("/<<'(LXC|NS1)'\n(.*?)\n\\1\n/s", $code, $inner, PREG_SET_ORDER);
            foreach ($inner as $m) {
                $this->assertBash($m[2], "{$name} ({$m[1]})");
                preg_match_all("/<<'PY'\n(.*?)\nPY\n/s", $m[2]."\n", $python);
                foreach ($python[1] as $script) {
                    $process = new Process(['python3', '-I', '-c', 'import ast, sys; ast.parse(sys.stdin.read())']);
                    $process->setInput($script)->run();
                    $this->assertTrue($process->isSuccessful(), "{$name}: {$process->getErrorOutput()}");
                }
            }
        }
    }

    public function test_it_does_not_touch_a_launched_session(): void
    {
        $incident = Incident::where('nom', 'Servidor de correu')->sole();
        $student = Student::whereHas('modules', fn ($q) => $q->where('moduls.codi', '9991'))->first();
        $this->assertNotNull($student);
        Queue::fake();
        app(IncidentLauncher::class)->launch($incident, [$student->id => [$incident->options->first()->id]], 'manual', 1, null, null);
        $this->assertSame(1, IncidentStudent::count());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ja s\'han llançat');
        $this->seed(Block12IncidentsSeeder::class);
    }

    public function test_it_needs_block_12(): void
    {
        Block::where('numero', 12)->delete();

        $this->expectException(RuntimeException::class);
        $this->seed(Block12IncidentsSeeder::class);
    }

    /** @return list<array{0: string, 1: Executor, 2: string, 3: ?array}> */
    private function codes(): array
    {
        $task = Block::where('numero', 12)->sole()->tasks()->where('titol', Block12IncidentsSeeder::TASK)->sole();

        return $task->checks()->get()->map(fn (Check $c) => [$c->nom, $c->executor, $c->currentVersion->codi, $c->currentVersion->parametres])
            ->merge(Action::where('tasca_id', $task->id)->get()->map(fn (Action $a) => [$a->nom, $a->executor, $a->currentVersion->codi, $a->currentVersion->parametres]))
            ->all();
    }

    private function assertBash(string $code, string $name): void
    {
        $process = new Process(['bash', '-n']);
        $process->setInput($code)->run();
        $this->assertTrue($process->isSuccessful() && $process->getErrorOutput() === '', "{$name}: {$process->getErrorOutput()}");
    }
}
