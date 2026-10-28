<?php

namespace Tests\Feature;

use App\Enums\Level;
use App\Enums\Role;
use App\Filament\Pages\InventoryOverview;
use App\Filament\Resources\Students\Pages\EditStudent;
use App\Models\Module;
use App\Models\Secret;
use App\Models\Student;
use App\Models\User;
use App\Models\Variable;
use App\Support\Demo;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Panell lateral d'un alumne a l'Inventari: què rep, d'on surt i les seves excepcions. */
class StudentPanelTest extends TestCase
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

    private function page(array $query = [])
    {
        return Livewire::test(InventoryOverview::class, ['modul' => $this->module->id, ...$query]);
    }

    /** Editar una variable pròpia s'obre des del panell de l'alumne. */
    private function editOwn(?string $key = null): array
    {
        return [
            TestAction::make('student')->arguments(['alumne' => $this->student->id]),
            TestAction::make('editStudentVariable')->arguments(array_filter(['alumne' => $this->student->id, 'clau' => $key])),
        ];
    }

    public function test_the_panel_shows_each_value_with_its_chain_of_levels(): void
    {
        Variable::create(['nivell' => Level::Global, 'clau' => 'xarxa_lan', 'valor' => '10.0.0.0/8']);
        $this->student->update(['variables' => ['ip_router' => '198.51.100.99']]);

        $panel = $this->page()->mountAction('student', ['alumne' => $this->student->id])->assertActionMounted('student')
            ->instance()->studentPanel($this->student->id);

        $this->assertSame(['global' => '10.0.0.0/8', 'modul' => '172.{{x}}.0.0/22'], $panel['chain']['xarxa_lan']);
        $this->assertSame('172.21.0.0/22', $panel['vars']['xarxa_lan']['valor']);
        $this->assertSame('198.51.100.99', $panel['chain']['ip_router']['propia']);
        $this->assertSame('propia', $panel['vars']['ip_router']['origen']);
        $this->assertSame('ssh_jaume', $panel['access']->clau);
        $this->assertContains('pve', $panel['hosts']->map(fn ($h) => $h['tipus']->nom)->all());
    }

    public function test_the_ansible_inventory_masks_passwords(): void
    {
        Secret::where('clau', 'winrm_windows')->sole()->update(['valor' => 'SuperSecret123']);

        $ansible = $this->page()->instance()->studentPanel($this->student->id)['ansible'];

        $this->assertStringContainsString('ansible_password', $ansible);
        $this->assertStringContainsString('ansible_become_password', $ansible);
        $this->assertStringNotContainsString('SuperSecret123', $ansible);
    }

    public function test_a_student_of_another_module_has_no_panel(): void
    {
        $other = Student::whereDoesntHave('modules', fn ($q) => $q->whereKey($this->module->id))->first()
            ?? Student::create(['usuari' => 'fora', 'nom_complet' => 'Fora', 'curs_id' => $this->module->curs_id, 'cicle_id' => $this->student->cicle_id]);

        $this->assertNull($this->page()->instance()->studentPanel($other->id));
    }

    public function test_an_exception_for_one_student_is_added_changed_and_removed_from_the_panel(): void
    {
        // El panell no té formulari: els errors es miren directament.
        $this->page()->callAction($this->editOwn(), ['clau' => 'ip_pve', 'valor' => '{{ xarxa_aula | host(9) }}'])->assertHasNoErrors();
        $this->assertSame('{{ xarxa_aula | host(9) }}', $this->student->fresh()->variables['ip_pve']);

        $this->page()->callAction($this->editOwn(), ['clau' => 'ip_pve', 'valor' => 'x'])->assertHasErrors(['mountedActions.1.data.clau']);

        $this->page()->callAction($this->editOwn('ip_pve'), ['valor' => '198.51.100.7'])->assertHasNoErrors();
        $this->assertSame('198.51.100.7', $this->student->fresh()->variables['ip_pve']);

        $this->page()->callAction([...$this->editOwn('ip_pve'), TestAction::make('deleteStudentVariable')]);
        $this->assertArrayNotHasKey('ip_pve', $this->student->fresh()->variables ?? []);
    }

    public function test_the_edit_previews_the_value_the_student_would_get(): void
    {
        $page = $this->page()->instance();

        $this->assertSame('198.51.100.9', $page->previewValue('ip_pve', 'propia', $this->student->id, '198.51.100.9'));
        $this->assertSame('{{ip_pve}}', $this->module->hostTypes()->where('nom', 'pve')->value('adreca'));
        $this->assertArrayNotHasKey('ip_pve', $this->student->fresh()->variables ?? []);
    }

    public function test_the_student_sheet_links_to_its_panel(): void
    {
        Livewire::test(EditStudent::class, ['record' => $this->student->id])
            ->assertOk()
            ->assertSee('Variables pròpies')
            ->assertActionHasUrl('conjunt', InventoryOverview::getUrl(['modul' => $this->module->id, 'alumne' => $this->student->id]));

        $this->page(['alumne' => $this->student->id])
            ->call('mountDefaultActions')
            ->assertSet('mountedActions.0.name', 'student')
            ->assertSet('mountedActions.0.arguments', ['alumne' => $this->student->id])
            ->assertSet('alumne', null);
    }

    public function test_demo_visitors_see_the_panel_but_cannot_add_exceptions(): void
    {
        config(['heimdeye.demo.enabled' => true]);
        $visitor = Demo::createVisitor();

        Livewire::actingAs($visitor)->test(InventoryOverview::class, ['modul' => $this->module->id])
            ->assertActionVisible('student', ['alumne' => $this->student->id])
            ->assertActionHidden($this->editOwn());
        $this->assertNotNull(Livewire::actingAs($visitor)->test(InventoryOverview::class, ['modul' => $this->module->id])->instance()->studentPanel($this->student->id));
    }
}
