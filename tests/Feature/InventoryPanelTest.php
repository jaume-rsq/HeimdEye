<?php

namespace Tests\Feature;

use App\Enums\Level;
use App\Enums\Role;
use App\Filament\Pages\InventoryOverview;
use App\Filament\Resources\Modules\ModuleResource;
use App\Filament\Resources\Students\Pages\CreateStudent;
use App\Filament\Resources\Students\Pages\EditStudent;
use App\Filament\Resources\Students\Pages\ListStudents;
use App\Models\Module;
use App\Models\Secret;
use App\Models\Student;
use App\Models\User;
use App\Models\Variable;
use App\Support\InventoryGrid;
use App\Support\VariableResolver;
use Filament\Facades\Filament;
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
        Variable::create(['nivell' => Level::Global, 'clau' => 'nom_prova', 'valor' => 'example.test']);
        Variable::create(['nivell' => Level::Course, 'curs_id' => $this->module->curs_id, 'clau' => 'nom_curs', 'valor' => 'curs']);
        $this->student->update(['variables' => [...$this->student->variables ?? [], 'ip_router' => '198.51.100.99']]);

        $vars = app(VariableResolver::class)->detailed($this->student->fresh(), $this->module);

        $this->assertSame('global', $vars['nom_prova']['origen']);
        $this->assertSame('curs', $vars['nom_curs']['origen']);
        $this->assertSame('modul', $vars['xarxa_lan']['origen']);
        $this->assertSame('alumne', $vars['usuari']['origen']);
        $this->assertSame(['valor' => '198.51.100.99', 'origen' => 'propia', 'tapa' => true, 'error' => null], $vars['ip_router']);
        $this->assertSame(
            array_map(fn ($v) => $v['valor'], $vars),
            app(VariableResolver::class)->resolve($this->student->fresh(), $this->module),
        );
    }

    public function test_the_grid_flags_hosts_whose_templates_cannot_be_rendered(): void
    {
        $this->student->update(['variables' => array_diff_key($this->student->variables ?? [], ['xarxa_aula' => true])]);

        $grid = app(InventoryGrid::class)->build($this->module);
        $row = collect($grid['rows'])->first(fn ($r) => $r['student']->is($this->student));

        $this->assertSame(['Falta la variable xarxa_aula'], $row['hosts']['pve']['problemes']);
        $this->assertSame([], $row['hosts']['dns']['problemes']);
        $this->assertGreaterThan(0, $row['problemes']);

        Livewire::test(InventoryOverview::class, ['modul' => $this->module->id])
            ->assertOk()
            ->assertSee('Falta la variable xarxa_aula')
            ->set('problemes', true)
            ->assertSee($this->student->usuari)
            ->set('vista', 'variables')
            ->assertSee('Xarxes')
            ->assertSee('xarxa_lan');
    }

    public function test_variables_are_grouped_by_prefix(): void
    {
        $this->assertSame(
            ['Alumne' => ['usuari', 'x'], 'Xarxes' => ['xarxa_aula', 'xarxa_lan'], 'Adreces' => ['ip_pve'], 'Altres' => ['zona']],
            InventoryGrid::groups(['zona', 'xarxa_lan', 'ip_pve', 'x', 'xarxa_aula', 'usuari']),
        );
    }

    public function test_the_student_sheet_shows_the_ansible_inventory_with_passwords_masked(): void
    {
        Secret::where('clau', 'jaume')->sole()->update(['valor' => 'SuperSecret123']);

        Livewire::test(EditStudent::class, ['record' => $this->student->id])
            ->assertOk()
            ->assertSee('Variables pròpies')
            ->assertSee('172.21.0.0/22')
            ->set('pestanya', 'equips')
            ->assertSee('ssh_jaume (del mòdul)')
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
            ->fillForm(['alies' => 'Fènix', 'num_taula' => 0, 'x' => 0, 'variables' => ['ip_router' => '198.51.100.99']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Fènix', $this->student->fresh()->alies);
        $this->assertSame(0, $this->student->fresh()->num_taula, 'la taula 0 és vàlida');
        $this->assertSame('198.51.100.99', $this->student->fresh()->variables['ip_router']);
    }

    public function test_students_can_be_filtered_by_module(): void
    {
        $module = Module::where('codi', '9991')->sole();

        Livewire::test(ListStudents::class)
            ->filterTable('modul', $module->id)
            ->assertCanSeeTableRecords([$this->student]);
    }

    public function test_the_menu_opens_the_only_module_of_the_current_year(): void
    {
        $module = Module::where('codi', '9991')->sole();

        $this->get(ModuleResource::getUrl())->assertRedirect(ModuleResource::getUrl('edit', ['record' => $module]));
    }

    public function test_a_new_student_sheet_can_be_linked_to_a_user_so_it_can_enter_its_panel(): void
    {
        $user = User::create(['nom' => 'Prova', 'email' => 'prova@example.com', 'password' => 'secret', 'rol' => Role::Student, 'es_actiu' => true]);
        $teacher = auth()->user();
        $this->actingAs($user)->get('/alumne')->assertForbidden();

        $this->actingAs($teacher);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(CreateStudent::class)
            ->fillForm([
                'usuari' => 'prova', 'nom_complet' => 'Prova Alumne', 'num_taula' => 0, 'x' => 0, 'usuari_id' => $user->id,
                'curs_id' => $this->student->curs_id, 'cicle_id' => $this->student->cicle_id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->actingAs($user->fresh())->followingRedirects()->get('/alumne')->assertOk();

        $this->actingAs($teacher);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(CreateStudent::class)
            ->fillForm(['usuari' => 'altra', 'nom_complet' => 'Altra', 'usuari_id' => $user->id, 'curs_id' => $this->student->curs_id, 'cicle_id' => $this->student->cicle_id])
            ->call('create')
            ->assertHasFormErrors(['usuari_id' => 'unique']);
    }
}
