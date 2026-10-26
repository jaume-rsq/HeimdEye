<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\InventoryOverview;
use App\Filament\Resources\Students\Pages\EditStudent;
use App\Models\Credential;
use App\Models\GlobalVar;
use App\Models\Module;
use App\Models\Student;
use App\Models\User;
use App\Support\InventoryGrid;
use App\Support\VariableResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Inventari: origen de les variables, vista de conjunt i fitxa d'alumne. */
class InventoryPanelTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Module $module;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->module = Module::where('codi', '9991')->firstOrFail();
        $this->student = $this->module->students()->orderBy('num_taula')->firstOrFail();
        $this->actingAs(User::create(['nom' => 'Prof', 'email' => 'prof@example.com', 'password' => 'secret', 'rol' => Role::Teacher, 'es_actiu' => true]));
    }

    public function test_each_variable_says_where_it_comes_from(): void
    {
        GlobalVar::create(['clau' => 'domini_prova', 'valor' => 'example.test']);
        $this->student->update(['variables' => [...$this->student->variables ?? [], 'router_ip' => '198.51.100.99']]);

        $vars = app(VariableResolver::class)->detailed($this->student->fresh(), $this->module);

        $this->assertSame('global', $vars['domini_prova']['origen']);
        $this->assertSame('modul', $vars['lan']['origen']);
        $this->assertSame('alumne', $vars['usuari']['origen']);
        $this->assertSame(['valor' => '198.51.100.99', 'origen' => 'propia', 'tapa' => true], $vars['router_ip']);
        $this->assertSame(
            array_map(fn ($v) => $v['valor'], $vars),
            app(VariableResolver::class)->resolve($this->student->fresh(), $this->module),
        );
    }

    public function test_the_grid_flags_hosts_whose_templates_cannot_be_rendered(): void
    {
        $this->student->update(['variables' => array_diff_key($this->student->variables ?? [], ['aula_net' => true])]);

        $grid = app(InventoryGrid::class)->build($this->module);
        $row = collect($grid['rows'])->first(fn ($r) => $r['student']->is($this->student));

        $this->assertSame(['Falta la variable aula_net'], $row['hosts']['pve']['problemes']);
        $this->assertSame([], $row['hosts']['dns']['problemes']);
        $this->assertGreaterThan(0, $row['problemes']);

        Livewire::test(InventoryOverview::class, ['modul' => $this->module->id])
            ->assertOk()
            ->assertSee('Falta la variable aula_net')
            ->set('problemes', true)
            ->assertSee($this->student->usuari)
            ->set('vista', 'variables')
            ->assertSee('aula_net');
    }

    public function test_the_student_sheet_shows_the_ansible_inventory_with_passwords_masked(): void
    {
        Credential::where('nom', 'WinRM Windows Server')->first()->update(['secret' => 'SuperSecret123']);

        Livewire::test(EditStudent::class, ['record' => $this->student->id])
            ->assertOk()
            ->assertSee('Variables pròpies')
            ->set('pestanya', 'equips')
            ->assertSee('WinRM Windows Server')
            ->set('pestanya', 'ansible')
            ->assertSee('ansible_password')
            ->assertDontSee('SuperSecret123')
            ->set('pestanya', 'activitat')
            ->assertSee('Darreres accions');
    }

    public function test_students_can_be_edited_from_the_list(): void
    {
        $this->get('/admin/alumnes')->assertOk()->assertSee($this->student->usuari);

        Livewire::test(EditStudent::class, ['record' => $this->student->id])
            ->fillForm(['alies' => 'Fènix', 'variables' => ['router_ip' => '198.51.100.99']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Fènix', $this->student->fresh()->alies);
        $this->assertSame('198.51.100.99', $this->student->fresh()->variables['router_ip']);
    }
}
