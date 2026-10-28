<?php

namespace Tests\Feature;

use App\Enums\Level;
use App\Enums\Role;
use App\Filament\Resources\HostTypes\Pages\ManageHostTypes;
use App\Models\AcademicYear;
use App\Models\HostType;
use App\Models\Module;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Tipus d'equip comuns (globals i de curs). */
class CommonInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::create(['nom' => 'Prof', 'email' => 'prof@example.com', 'password' => 'secret', 'rol' => Role::Teacher, 'es_actiu' => true]));
    }

    public function test_common_host_types_list_only_global_and_course_ones(): void
    {
        Livewire::test(ManageHostTypes::class)
            ->assertOk()
            ->assertSee('zabbix')
            ->assertDontSee('vm_router'); // és del mòdul
    }

    public function test_a_module_sees_global_and_its_course_host_types_and_overrides_them_by_name(): void
    {
        $module = Module::where('codi', '9991')->firstOrFail();
        $other = AcademicYear::create(['nom' => '2099-00', 'data_inici' => '2099-09-01', 'data_fi' => '2100-06-30', 'es_actual' => false]);
        HostType::create(['nivell' => Level::Course, 'curs_id' => $module->curs_id, 'nom' => 'nas', 'grup_ansible' => 'nas', 'es_unic' => true, 'nom_host' => 'nas', 'adreca' => '198.51.100.9']);
        HostType::create(['nivell' => Level::Course, 'curs_id' => $other->id, 'nom' => 'altre', 'grup_ansible' => 'altres', 'nom_host' => 'altre', 'adreca' => '198.51.100.8']);

        $visible = $module->visibleHostTypes()->keyBy('nom');
        $this->assertTrue($visible['zbx']->isUnique());
        $this->assertSame(Level::Global, $visible['zbx']->nivell);
        $this->assertSame(Level::Course, $visible['nas']->nivell);
        $this->assertArrayNotHasKey('altre', $visible);
        $this->assertArrayHasKey('pve', $visible);

        // Un del mòdul amb el mateix nom tapa el global.
        $module->hostTypes()->create(['nom' => 'zbx', 'grup_ansible' => 'zabbix_proves', 'nom_host' => 'zbx', 'adreca' => '127.0.0.1', 'es_unic' => true]);
        $this->assertSame('zabbix_proves', $module->visibleHostTypes()->firstWhere('nom', 'zbx')->grup_ansible);
    }

    public function test_a_common_host_type_is_unique_within_its_level(): void
    {
        $year = AcademicYear::where('es_actual', true)->firstOrFail();
        $data = ['nivell' => Level::Course->value, 'curs_id' => $year->id, 'nom' => 'nas', 'grup_ansible' => 'nas', 'es_unic' => true, 'nom_host' => 'nas', 'adreca' => '198.51.100.9'];

        Livewire::test(ManageHostTypes::class)->callAction('create', $data)->assertHasNoActionErrors();
        $type = HostType::where('nom', 'nas')->sole();
        $this->assertSame($year->id, $type->curs_id);
        $this->assertNull($type->modul_id);

        Livewire::test(ManageHostTypes::class)->callAction('create', $data)->assertHasActionErrors(['nom']);
        Livewire::test(ManageHostTypes::class)->callAction('create', ['nivell' => Level::Global->value] + $data)->assertHasNoActionErrors();
        $this->assertNull(HostType::where('nom', 'nas')->where('nivell', Level::Global)->sole()->curs_id);
    }
}
