<?php

namespace Tests\Feature;

use App\Enums\Executor;
use App\Enums\Role;
use App\Filament\Auth\DemoLogin;
use App\Filament\Pages\InventoryOverview;
use App\Filament\Resources\Actions\ActionResource;
use App\Filament\Resources\Checks\CheckResource;
use App\Filament\Resources\Students\StudentResource;
use App\Models\Check;
use App\Models\Module;
use App\Models\Student;
use App\Models\Task;
use App\Models\User;
use App\Runner\CheckRunner;
use App\Runner\ProcessRunner;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/** Mode demo: entrada sense contrasenya, permisos de visitant, límits d'execució i neteja. */
class DemoModeTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Module $module;

    protected function setUp(): void
    {
        parent::setUp();

        config(['heimdeye.demo.enabled' => true]);
        $this->module = Module::where('codi', '9991')->firstOrFail();
    }

    private function check(?User $author = null): Check
    {
        $task = Task::whereHas('block', fn ($q) => $q->where('modul_id', $this->module->id))->firstOrFail();
        $check = $task->checks()->create(['nom' => 'Prova', 'executor' => Executor::Local, 'es_actiu' => true]);
        $check->versions()->create(['versio' => 1, 'codi' => 'echo hola', 'temps_max_seg' => 10, 'creat_per' => $author?->id]);

        return $check->fresh();
    }

    public function test_the_login_button_creates_a_visitor_teacher(): void
    {
        Livewire::test(DemoLogin::class)->call('authenticate');

        $visitor = auth()->user();
        $this->assertNotNull($visitor);
        $this->assertTrue(Demo::isVisitor($visitor));
        $this->assertSame(Role::Teacher, $visitor->rol);
        $this->assertTrue($visitor->modules()->whereKey($this->module->id)->exists());
    }

    public function test_visitors_cannot_see_users_or_credentials_nor_change_the_inventory(): void
    {
        $this->actingAs(Demo::createVisitor());

        $this->get('/admin/usuaris')->assertForbidden();
        Livewire::test(InventoryOverview::class, ['modul' => $this->module->id, 'pestanya' => 'secrets'])
            ->assertSet('pestanya', 'variables')
            ->assertDontSee('api_zabbix')
            ->assertActionHidden('createVariable')
            ->assertActionHidden('createSecret');
        $this->get(StudentResource::getUrl())->assertOk();
        $this->assertFalse(StudentResource::canCreate());
        $this->assertFalse(StudentResource::canEdit(Student::firstOrFail()));
    }

    public function test_visitors_can_look_at_the_inventory_of_a_module(): void
    {
        $visitor = Demo::createVisitor();
        $this->module->secrets()->create(['clau' => 'clau_demo', 'valor' => 'no-es-veu']);

        Livewire::actingAs($visitor)->test(InventoryOverview::class, ['modul' => $this->module->id, 'pestanya' => 'equips'])
            ->assertOk()
            ->assertSee('vm_router')
            ->assertDontSee('no-es-veu')
            ->assertActionHidden('createHostType')
            ->assertActionHidden('moduleAccess');
        Livewire::actingAs($visitor)->test(InventoryOverview::class, ['modul' => $this->module->id, 'pestanya' => 'variables'])
            ->assertOk()
            ->assertSee('xarxa_lan')
            ->assertDontSee('clau_demo');
    }

    public function test_visitors_only_edit_what_they_created(): void
    {
        $visitor = Demo::createVisitor();
        $this->actingAs($visitor);

        $this->assertFalse(CheckResource::canEdit($this->check()));
        $this->assertTrue(CheckResource::canEdit($this->check($visitor)));

        $action = $this->module->actions()->create(['nom' => 'Comuna', 'executor' => Executor::Local]);
        $action->versions()->create(['versio' => 1, 'codi' => 'echo hola', 'temps_max_seg' => 10]);
        $this->assertFalse(ActionResource::canEdit($action));
        $this->assertTrue(ActionResource::canCreate());
    }

    public function test_admins_are_not_restricted(): void
    {
        $admin = User::create(['nom' => 'Admin', 'email' => 'cap@example.com', 'password' => 'secret', 'rol' => Role::Admin, 'es_actiu' => true]);
        $this->actingAs($admin);

        $this->assertTrue(CheckResource::canEdit($this->check()));
        $this->assertTrue(StudentResource::canCreate());
    }

    public function test_runs_per_hour_are_limited(): void
    {
        Queue::fake();
        config(['heimdeye.demo.runs_per_hour' => 3]);
        $visitor = Demo::createVisitor();
        $check = $this->check($visitor);
        $students = $this->module->students()->orderBy('num_taula')->take(2)->get();

        app(CheckRunner::class)->launch($check, $students, $visitor);

        $this->expectException(InvalidArgumentException::class);
        app(CheckRunner::class)->launch($check, $students, $visitor);
    }

    public function test_the_timeout_is_capped(): void
    {
        config(['heimdeye.demo.max_timeout' => 1]);

        $result = app(ProcessRunner::class)->execute(['sleep', '5'], [], '', 60);

        $this->assertTrue($result['timedOut']);
        $this->assertLessThan(4000, $result['durationMs']);
    }

    public function test_cleanup_removes_inactive_visitors_and_their_checks(): void
    {
        $old = Demo::createVisitor();
        $old->forceFill(['created_at' => now()->subDay()])->save();
        $mine = $this->check($old);
        $common = $this->check();
        $recent = Demo::createVisitor();

        $this->artisan('heimdeye:demo-cleanup')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelMissing($mine);
        $this->assertModelExists($common);
        $this->assertModelExists($recent);
    }

    public function test_nothing_changes_outside_demo_mode(): void
    {
        config(['heimdeye.demo.enabled' => false]);
        $teacher = User::create(['nom' => 'Prof', 'email' => 'prof@example.com', 'password' => 'secret', 'rol' => Role::Teacher, 'es_actiu' => true]);
        $teacher->modules()->attach($this->module);
        $this->actingAs($teacher);

        $this->assertTrue(CheckResource::canEdit($this->check()));
        $this->assertTrue(StudentResource::canCreate());
        Livewire::test(InventoryOverview::class, ['modul' => $this->module->id, 'pestanya' => 'secrets'])
            ->assertSet('pestanya', 'secrets')
            ->assertSee('api_zabbix')
            ->assertActionVisible('createSecret');
    }
}
