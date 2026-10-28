<?php

namespace Tests\Feature;

use App\Enums\Level;
use App\Enums\Role;
use App\Filament\Pages\InventoryOverview;
use App\Models\Module;
use App\Models\Student;
use App\Models\User;
use App\Models\Variable;
use App\Runner\Ansible\InventoryBuilder;
use App\Runner\TempFiles;
use App\Support\VariableResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Variables pròpies dels alumnes i variables dels tipus d'equip, a la taula de variables. */
class OwnVariablesTest extends TestCase
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
    }

    public function test_student_variables_are_rows_that_follow_the_array(): void
    {
        $this->student->update(['variables' => ['xarxa_aula' => '198.51.100.16/28', 'ip_extra' => '192.0.2.1']]);
        $this->assertSame(
            ['xarxa_aula' => '198.51.100.16/28', 'ip_extra' => '192.0.2.1'],
            Variable::where('alumne_id', $this->student->id)->where('nivell', Level::Student)->orderBy('id')->pluck('valor', 'clau')->all(),
        );

        $this->student->update(['variables' => ['ip_extra' => '192.0.2.2']]);
        $this->assertSame(['ip_extra' => '192.0.2.2'], $this->student->fresh()->variables);

        $this->student->delete();
        $this->assertSame(0, Variable::where('alumne_id', $this->student->id)->count());
    }

    public function test_host_type_variables_are_rows_and_ansible_gets_them_typed(): void
    {
        $type = $this->module->hostTypes()->create([
            'nom' => 'web', 'grup_ansible' => 'web', 'nom_host' => 'web{{num_taula}}', 'adreca' => '{{ip_pve}}',
            'variables_connexio' => ['ansible_port' => 2222, 'ansible_become' => true, 'web_root' => '/srv/u{{num_taula}}', 'codi' => '007'],
        ]);
        $this->assertSame(
            ['ansible_port' => '2222', 'ansible_become' => 'true', 'web_root' => '/srv/u{{num_taula}}', 'codi' => '007'],
            Variable::where('tipus_equip_id', $type->id)->where('nivell', Level::HostType)->orderBy('id')->pluck('valor', 'clau')->all(),
        );

        $builder = new InventoryBuilder(app(VariableResolver::class), $files = new TempFiles);
        $host = $builder->build($this->module, [$this->student], collect([$type->fresh()]))['all']['children']['web']['hosts']['web01'];
        $files->cleanup();

        $this->assertSame(2222, $host['ansible_port']);
        $this->assertTrue($host['ansible_become']);
        $this->assertSame('/srv/u01', $host['web_root']);
        $this->assertSame('007', $host['codi'], 'un zero al davant vol dir text');

        $type->delete();
        $this->assertSame(0, Variable::where('tipus_equip_id', $type->id)->count());
    }

    public function test_the_variables_tab_only_shows_common_and_module_levels(): void
    {
        $this->actingAs(User::create(['nom' => 'Prof', 'email' => 'prof@example.com', 'password' => 'secret', 'rol' => Role::Teacher, 'es_actiu' => true]));
        $this->student->update(['variables' => [...$this->student->variables, 'nomes_alumne' => 'x']]);

        $matrix = Livewire::test(InventoryOverview::class, ['modul' => $this->module->id, 'pestanya' => 'variables'])->instance()->variableMatrix;

        $this->assertArrayNotHasKey('nomes_alumne', $matrix);
        $this->assertArrayNotHasKey('ansible_connection', $matrix);
    }
}
