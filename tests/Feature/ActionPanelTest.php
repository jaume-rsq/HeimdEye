<?php

namespace Tests\Feature;

use App\Enums\ActionOverview;
use App\Enums\ActionStatus;
use App\Enums\Executor;
use App\Enums\Role;
use App\Enums\RunOrigin;
use App\Filament\Resources\Actions\Pages\CreateAction;
use App\Filament\Resources\Actions\Pages\EditAction;
use App\Filament\Resources\Actions\Pages\ListActions;
use App\Filament\Resources\Actions\Pages\ManageActionRuns;
use App\Jobs\RunAction;
use App\Models\Action;
use App\Models\ActionRun;
use App\Models\Check;
use App\Models\CheckRun;
use App\Models\Module;
use App\Models\RunBatch;
use App\Models\Student;
use App\Models\User;
use App\Support\ActionSummary;
use App\Support\VariableResolver;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** Panell d'accions: accés, estat global, versions i llançament. */
class ActionPanelTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Module $module;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->module = Module::where('codi', '9991')->firstOrFail();
        $this->teacher = User::create(['nom' => 'Prof', 'email' => 'prof@example.com', 'password' => 'secret', 'rol' => Role::Teacher, 'es_actiu' => true]);
    }

    private function action(array $params = []): Action
    {
        $action = $this->module->actions()->create(['nom' => 'Prova', 'executor' => Executor::Local]);
        $action->versions()->create(['versio' => 1, 'codi' => 'echo hola', 'parametres' => $params, 'temps_max_seg' => 60]);

        return $action->fresh();
    }

    private function record(Action $action, Student $student, ActionStatus $status, bool $simulate = false): void
    {
        $batch = RunBatch::create(['modul_id' => $this->module->id, 'origen' => RunOrigin::Action, 'data_inici' => now(), 'data_fi' => now()]);
        $action->runs()->create([
            'execucio_id' => $batch->id, 'versio_accio_id' => $action->currentVersion->id, 'alumne_id' => $student->id,
            'es_simulacio' => $simulate, 'estat' => $status, 'data_inici' => now(), 'data_fi' => now(),
        ]);
    }

    public function test_only_active_teachers_enter_the_panel(): void
    {
        $this->get('/admin/accions')->assertRedirect('/admin/login');
        $this->actingAs($this->teacher)->get('/admin/accions')->assertOk();

        $student = User::create(['nom' => 'Alumne', 'email' => 'alumne@example.com', 'rol' => Role::Student, 'es_actiu' => true]);
        $this->actingAs($student)->get('/admin/accions')->assertForbidden();
    }

    public function test_cloudflare_mode_logs_in_by_the_jwt_email(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $rsa = openssl_pkey_get_details($key)['rsa'];
        $b64 = fn (string $bin) => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
        Http::fake(['equip.cloudflareaccess.com/*' => Http::response(['keys' => [
            ['kty' => 'RSA', 'kid' => 'k1', 'alg' => 'RS256', 'use' => 'sig', 'n' => $b64($rsa['n']), 'e' => $b64($rsa['e'])],
        ]])]);
        config([
            'heimdeye.auth.mode' => 'cloudflare',
            'heimdeye.auth.cloudflare' => ['team_domain' => 'equip.cloudflareaccess.com', 'aud' => 'aud123'],
        ]);
        $token = fn (string $email, string $aud = 'aud123') => JWT::encode(
            ['aud' => [$aud], 'iss' => 'https://equip.cloudflareaccess.com', 'email' => $email, 'exp' => time() + 60],
            $private, 'RS256', 'k1',
        );

        $this->get('/admin/accions')->assertUnauthorized();
        $this->get('/admin/accions', ['Cf-Access-Jwt-Assertion' => $token('PROF@example.com', 'altra')])->assertUnauthorized();
        $this->get('/admin/accions', ['Cf-Access-Jwt-Assertion' => $token('desconegut@example.com')])->assertForbidden();
        $this->get('/admin/accions', ['Cf-Access-Jwt-Assertion' => $token('PROF@example.com')])->assertOk();
        $this->assertAuthenticatedAs($this->teacher);
    }

    public function test_overview_uses_the_latest_real_run_of_each_active_student(): void
    {
        $action = $this->action();
        $students = $this->module->students()->where('es_actiu', true)->get();
        $this->assertSame(ActionOverview::Never, ActionSummary::for($action)->overview());

        $this->record($action, $students[0], ActionStatus::Ok);
        $this->record($action, $students[1], ActionStatus::Failed, simulate: true);
        $this->assertSame(ActionOverview::Partial, ActionSummary::for($action)->overview());

        foreach ($students as $student) {
            $this->record($action, $student, ActionStatus::Changed);
        }
        $this->assertSame(ActionOverview::Ok, ActionSummary::for($action)->overview());

        $this->record($action, $students[1], ActionStatus::Unavailable);
        $summary = ActionSummary::for($action);
        $this->assertSame(ActionOverview::SomeFailed, $summary->overview());
        $this->assertTrue($summary->has(ActionStatus::Unavailable, all: false));
        $this->assertFalse($summary->has(ActionStatus::Changed, all: true));

        foreach ($students as $student) {
            $this->record($action, $student, ActionStatus::Error);
        }
        $this->assertSame(ActionOverview::AllFailed, ActionSummary::for($action)->overview());
    }

    public function test_list_filters_by_status_for_all_or_some_students(): void
    {
        $this->actingAs($this->teacher);
        $failing = $this->action();
        $clean = $this->action();
        $students = $this->module->students()->where('es_actiu', true)->get();
        foreach ($students as $i => $student) {
            $this->record($failing, $student, $i === 0 ? ActionStatus::Failed : ActionStatus::Ok);
            $this->record($clean, $student, ActionStatus::Ok);
        }

        Livewire::test(ListActions::class)
            ->filterTable('execucions', ['estat' => 'failed', 'abast' => 'algun'])
            ->assertCanSeeTableRecords([$failing])
            ->assertCanNotSeeTableRecords([$clean])
            ->filterTable('execucions', ['estat' => 'ok', 'abast' => 'tots'])
            ->assertCanSeeTableRecords([$clean])
            ->assertCanNotSeeTableRecords([$failing]);
    }

    public function test_simulations_only_show_in_the_last_simulation_view(): void
    {
        $this->actingAs($this->teacher);
        $action = $this->action();
        $student = $this->module->students()->firstOrFail();
        $this->record($action, $student, ActionStatus::Ok);
        $this->record($action, $student, ActionStatus::Changed, simulate: true);
        [$real, $simulated] = [$action->runs()->where('es_simulacio', false)->first(), $action->runs()->where('es_simulacio', true)->first()];

        Livewire::test(ManageActionRuns::class, ['record' => $action->id])
            ->assertCanSeeTableRecords([$real])
            ->assertCanNotSeeTableRecords([$simulated]);
        Livewire::withQueryParams(['simulacio' => 1])->test(ManageActionRuns::class, ['record' => $action->id])
            ->assertCanSeeTableRecords([$simulated])
            ->assertCanNotSeeTableRecords([$real]);
    }

    public function test_create_makes_version_one_and_edit_only_versions_real_changes(): void
    {
        $this->actingAs($this->teacher);
        $student = $this->module->students()->orderBy('num_taula')->firstOrFail();

        $create = Livewire::test(CreateAction::class)
            ->fillForm([
                'nom' => 'Nova', 'modul_id' => $this->module->id, 'executor' => 'local',
                'codi' => 'echo hola $HE_PARAM_NOM', 'parametres' => [['name' => 'nom', 'label' => 'Nom', 'default' => 'x']],
                'temps_max_seg' => 30,
            ])
            ->call('create');
        $this->assertSame(0, Action::where('nom', 'Nova')->count(), 'no es desa sense provar');

        $create->callAction('provarExecutar', ['alumnes' => [(string) $student->id]])
            ->assertSet('testResults.0.estat', 'ok')
            ->assertSet('testResults.0.sortida', fn ($out) => str_contains($out, 'hola x'))
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertSame(0, ActionRun::count(), 'les proves no es desen');
        $action = Action::where('nom', 'Nova')->firstOrFail();
        $this->assertSame([1], $action->versions()->pluck('versio')->all());
        $this->assertSame($this->teacher->id, $action->currentVersion->creat_per);

        Livewire::test(EditAction::class, ['record' => $action->id])
            ->fillForm(['descripcio' => 'Només canvia la descripció'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(1, $action->versions()->count());

        $edit = Livewire::test(EditAction::class, ['record' => $action->id])
            ->fillForm(['codi' => 'echo adeu', 'comentari' => 'Diu adeu'])
            ->call('save');
        $this->assertSame(1, $action->versions()->count(), 'una versió nova cal provar-la');

        $edit->callAction('provarExecutar', ['alumnes' => [(string) $student->id]])
            ->call('save')
            ->assertHasNoFormErrors();
        $action->refresh();
        $this->assertSame(2, $action->currentVersion->versio);
        $this->assertSame('echo adeu', $action->currentVersion->codi);
        $this->assertSame('Diu adeu', $action->currentVersion->comentari);
        $this->assertSame(Executor::Local, $action->executor);
    }

    public function test_verify_with_a_check_shows_the_result_without_saving_it(): void
    {
        $this->actingAs($this->teacher);
        $action = $this->action();
        $student = $this->module->students()->firstOrFail();
        $check = Check::whereHas('task.block', fn ($q) => $q->where('modul_id', $this->module->id))->firstOrFail();
        $check->versions()->create(['versio' => 99, 'codi' => 'echo "verificat"', 'temps_max_seg' => 10]);
        $check->executor === Executor::Local || $this->markTestSkipped('el primer check no és local');

        Livewire::test(EditAction::class, ['record' => $action->id])
            ->callAction('provarCheck', ['check_id' => $check->id, 'alumnes' => [(string) $student->id]])
            ->assertSet('testResults.0.titol', "Check «{$check->nom}»")
            ->assertSet('testResults.0.estat', 'pass');
        $this->assertSame(0, CheckRun::count());
    }

    public function test_a_failing_job_leaves_an_error_in_the_history(): void
    {
        $action = $this->action([['name' => 'obligatori', 'label' => 'Obligatori', 'default' => null]]);
        $student = $this->module->students()->firstOrFail();

        RunAction::dispatchSync($action, [$student->id]);

        $run = $action->runs()->sole();
        $this->assertSame(ActionStatus::Error, $run->estat);
        $this->assertStringContainsString('obligatori', $run->sortida);
    }

    public function test_execute_for_one_student_sends_the_values_computed_for_that_student(): void
    {
        Queue::fake();
        $this->actingAs($this->teacher);
        $action = $this->action([['name' => 'usuari_pve', 'label' => 'Usuari', 'default' => 'he{{num_taula}}']]);
        $student = $this->module->students()->orderBy('num_taula')->firstOrFail();
        $expected = 'he'.app(VariableResolver::class)->resolve($student, $this->module)['num_taula'];

        Livewire::test(ManageActionRuns::class, ['record' => $action->id])
            ->mountAction('executar')
            ->setActionData(['alumnes' => [(string) $student->id]])
            ->assertActionDataSet(['parametres.usuari_pve' => $expected])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        Queue::assertPushed(RunAction::class, fn (RunAction $job) => $job->studentIds === [$student->id]
            && $job->params === ['usuari_pve' => $expected]
            && ! $job->checkMode
            && $job->user->is($this->teacher));
    }
}
