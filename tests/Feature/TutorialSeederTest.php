<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Action;
use App\Models\Check;
use App\Models\Module;
use App\Runner\ContextBuilder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Tutorial: un curs a part, amb el que cal per executar-lo, i l'exportació a Markdown. */
class TutorialSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);
    }

    /** Checks del tutorial (la BBDD de tests pot tenir també els de SMIX). */
    private function checks()
    {
        return Check::whereHas('task.block.module.academicYear', fn ($q) => $q->where('nom', 'Tutorial'));
    }

    public function test_the_tutorial_is_its_own_course(): void
    {
        $year = AcademicYear::where('nom', 'Tutorial')->firstOrFail();
        $modules = Module::where('curs_id', $year->id)->orderBy('codi')->get();

        $this->assertSame(['TUT1', 'TUT2'], $modules->pluck('codi')->all());
        foreach ($modules as $module) {
            $this->assertCount(4, $module->students);
            $this->assertSame(['servidor'], $module->hostTypes->pluck('nom')->all());
        }
        $this->assertSame(6, $this->checks()->count());
        $this->assertSame(3, Action::whereIn('modul_id', $modules->modelKeys())->count());
        $this->assertCount(2, Action::where('nom', 'Publicar la web')->firstOrFail()->currentVersion->checks_verificacio);
    }

    public function test_seeding_twice_changes_nothing(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertSame(6, $this->checks()->count());
        $this->assertSame(1, $this->checks()->firstOrFail()->versions()->count());
    }

    public function test_every_check_builds_a_context_for_the_first_student(): void
    {
        foreach ($this->checks()->with('currentVersion', 'task.block.module')->get() as $check) {
            $module = $check->task->block->module;
            $student = $module->students()->orderBy('num_taula')->firstOrFail();
            $context = app(ContextBuilder::class)->build($module, $student, $check->executor, $check->currentVersion->codi, 15);

            $this->assertSame('alumne01', $context->env['HE_IP_SERVIDOR'], $check->nom);
            if ($check->executor->value === 'ssh') {
                $this->assertSame('alumne01', $context->targets[0]->host, $check->nom);
                $this->assertSame('alumne', $context->targets[0]->user, $check->nom);
            }
        }
    }

    public function test_it_exports_to_markdown(): void
    {
        $path = 'storage/framework/testing-tutorial.md';
        $this->artisan('heimdeye:tutorial', ['--output' => $path])->assertSuccessful();

        $md = file_get_contents(base_path($path));
        unlink(base_path($path));
        $this->assertStringContainsString('## TUT1 · Primers passos amb els checks', $md);
        $this->assertStringContainsString('#### Acció: Publicar la web', $md);
        $this->assertStringContainsString('Verifica amb: «El servidor web respon»', $md);
    }
}
