<?php

namespace Tests\Feature;

use App\Enums\Level;
use App\Enums\Role;
use App\Filament\Pages\InventoryOverview;
use App\Models\AcademicYear;
use App\Models\HostType;
use App\Models\Module;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Pestanya Equips de l'Inventari: tipus d'equip globals, del curs i del mòdul. */
class CommonInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Module $module;

    protected function setUp(): void
    {
        parent::setUp();

        $this->module = Module::where('codi', '9991')->firstOrFail();
        $this->actingAs(User::create(['nom' => 'Prof', 'email' => 'prof@example.com', 'password' => 'secret', 'rol' => Role::Teacher, 'es_actiu' => true]));
    }

    private function page(?int $module = null)
    {
        return Livewire::test(InventoryOverview::class, ['modul' => $module ?? $this->module->id, 'pestanya' => 'equips']);
    }

    private function edit(string $name, Level $level): TestAction
    {
        return TestAction::make('editHostType')->arguments(['nom' => $name, 'nivell' => $level->value]);
    }

    public function test_the_equips_tab_shows_the_host_types_of_every_level(): void
    {
        $this->page()->assertOk()->assertSee('zbx')->assertSee('vm_router')->assertSee('Mòdul 9991');

        // Només comuns: sense els del mòdul.
        $this->page(0)->assertOk()->assertSee('zbx')->assertDontSee('vm_router');
    }

    public function test_a_module_sees_global_and_its_course_host_types_and_overrides_them_by_name(): void
    {
        $other = AcademicYear::create(['nom' => '2099-00', 'data_inici' => '2099-09-01', 'data_fi' => '2100-06-30', 'es_actual' => false]);
        HostType::create(['nivell' => Level::Course, 'curs_id' => $this->module->curs_id, 'nom' => 'nas', 'grup_ansible' => 'nas', 'es_unic' => true, 'nom_host' => 'nas', 'adreca' => '198.51.100.9']);
        HostType::create(['nivell' => Level::Course, 'curs_id' => $other->id, 'nom' => 'altre', 'grup_ansible' => 'altres', 'nom_host' => 'altre', 'adreca' => '198.51.100.8']);

        $visible = $this->module->visibleHostTypes()->keyBy('nom');
        $this->assertTrue($visible['zbx']->isUnique());
        $this->assertSame(Level::Global, $visible['zbx']->nivell);
        $this->assertSame(Level::Course, $visible['nas']->nivell);
        $this->assertArrayNotHasKey('altre', $visible);
        $this->assertArrayHasKey('pve', $visible);

        // Una casella buida del mòdul parteix del global i, en desar-la, el tapa.
        $this->page()
            ->mountAction($this->edit('zbx', Level::Module))
            ->assertSchemaStateSet(['grup_ansible' => 'zabbix', 'es_unic' => true])
            ->fillForm(['grup_ansible' => 'zabbix_proves'])
            ->callMountedAction()
            ->assertHasNoActionErrors();
        $this->assertSame('zabbix_proves', $this->module->visibleHostTypes()->firstWhere('nom', 'zbx')->grup_ansible);
        $this->assertSame('zabbix', HostType::where('nom', 'zbx')->where('nivell', Level::Global)->value('grup_ansible'));

        // Esborrar-lo del mòdul torna a deixar manar el global.
        $this->page()->callAction([$this->edit('zbx', Level::Module), TestAction::make('deleteHostType')]);
        $this->assertSame('zabbix', $this->module->visibleHostTypes()->firstWhere('nom', 'zbx')->grup_ansible);
    }

    public function test_a_new_host_type_goes_to_its_level_and_is_unique_there(): void
    {
        $year = AcademicYear::where('es_actual', true)->firstOrFail();
        $data = ['nivell' => Level::Course->value, 'nom' => 'nas', 'grup_ansible' => 'nas', 'es_unic' => true, 'nom_host' => 'nas', 'adreca' => '198.51.100.9'];

        $this->page()->callAction('createHostType', $data)->assertHasNoActionErrors();
        $type = HostType::where('nom', 'nas')->sole();
        $this->assertSame($year->id, $type->curs_id);
        $this->assertNull($type->modul_id);

        $this->page()->callAction('createHostType', $data)->assertHasActionErrors(['nom']);
        $this->page()->callAction('createHostType', ['nivell' => Level::Global->value] + $data)->assertHasNoActionErrors();
        $this->assertNull(HostType::where('nom', 'nas')->where('nivell', Level::Global)->sole()->curs_id);
    }

    public function test_the_edit_previews_the_host_for_a_student_without_saving(): void
    {
        $student = $this->module->students()->where('es_actiu', true)->orderBy('num_taula')->firstOrFail();
        $page = $this->page()->instance();

        $this->assertSame('pve01-alumne01 → 198.51.100.20', $page->previewHost(['nom_host' => 'pve{{num_taula}}-{{usuari}}', 'adreca' => '{{ xarxa_aula | host(4) }}'], $student->id));
        $this->assertStringContainsString('No s\'ha pogut calcular', $page->previewHost(['nom_host' => 'x', 'adreca' => '{{ xarxa_aula | host(99) }}'], $student->id));
        $this->assertSame('{{ip_pve}}', $this->module->hostTypes()->where('nom', 'pve')->value('adreca'));
    }

    public function test_saving_a_broken_template_warns_with_the_affected_students(): void
    {
        $this->page()
            ->callAction($this->edit('router', Level::Module), ['adreca' => '{{ inexistent }}'])
            ->assertNotified('Hi ha plantilles que no es poden calcular');
    }

    public function test_the_module_default_access_is_set_from_the_tab(): void
    {
        $secret = $this->module->secrets()->create(['clau' => 'clau_nova', 'tipus' => 'clau_ssh', 'nom_usuari' => 'jaume', 'valor' => 'k']);

        $this->page()->callAction('moduleAccess', ['secret_acces_id' => $secret->id])->assertHasNoActionErrors();
        $this->assertSame($secret->id, $this->module->fresh()->secret_acces_id);
    }

    public function test_the_old_screens_redirect_to_the_tab(): void
    {
        $this->get('/admin/equips')->assertRedirect('/admin/inventari?pestanya=equips&modul=0');
        $this->get('/admin/moduls')->assertRedirect('/admin/inventari?pestanya=equips');
        $this->get("/admin/moduls/{$this->module->id}/equips-i-variables")->assertRedirect("/admin/inventari?pestanya=equips&modul={$this->module->id}");
    }
}
