<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\InventoryOverview;
use App\Models\HostType;
use App\Models\Module;
use App\Models\Student;
use App\Models\User;
use App\Support\ConnectionTester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Prova de connexió: ping amb Ansible als equips de l'inventari, sense desar res. */
class ConnectionTestTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Module $module;

    private HostType $local;

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_executable(trim((string) shell_exec('command -v ansible-playbook')))) {
            $this->markTestSkipped('ansible-playbook no està instal·lat');
        }

        $this->module = Module::where('codi', '9991')->firstOrFail();
        $this->module->hostTypes()->delete();
        $this->local = $this->module->hostTypes()->create([
            'nom' => 'local', 'grup_ansible' => 'locals', 'nom_host' => 'local{{num_taula}}', 'adreca' => '127.0.0.1', 'posicio' => 1,
            'variables_connexio' => ['ansible_connection' => 'local', 'ansible_python_interpreter' => 'auto_silent'],
        ]);
        $this->actingAs(User::create(['nom' => 'Prof', 'email' => 'prof@example.com', 'password' => 'secret', 'rol' => Role::Teacher, 'es_actiu' => true]));
    }

    public function test_hosts_are_pinged_and_template_problems_are_not_run(): void
    {
        $broken = $this->module->hostTypes()->create(['nom' => 'trencat', 'grup_ansible' => 'trencats', 'nom_host' => 'trencat{{num_taula}}', 'adreca' => '{{ip_inexistent}}', 'posicio' => 2]);
        $api = $this->module->hostTypes()->create([
            'nom' => 'api', 'grup_ansible' => 'apis', 'es_unic' => true, 'nom_host' => 'api', 'adreca' => '127.0.0.1', 'posicio' => 3,
            'variables_connexio' => ['ansible_connection' => 'httpapi', 'ansible_httpapi_port' => 1],
        ]);
        // Windows va en una execució a part: si falla (p. ex. sense ansible.windows), la resta no se n'assabenta.
        $windows = $this->module->hostTypes()->create([
            'nom' => 'win', 'grup_ansible' => 'wins', 'nom_host' => 'win{{num_taula}}', 'adreca' => '127.0.0.1', 'posicio' => 4,
            'variables_connexio' => ['ansible_connection' => 'winrm', 'ansible_port' => 1],
        ]);
        $students = Student::whereIn('usuari', ['alumne01', 'alumne02'])->orderBy('num_taula')->get();

        $results = app(ConnectionTester::class)->test($this->module, $students, collect([$this->local, $broken, $api, $windows]));

        foreach ($students as $student) {
            $this->assertSame('ok', $results[ConnectionTester::key($student->id, $this->local->id)]['estat']);
            $this->assertSame(['estat' => 'error', 'missatge' => 'Falta la variable ip_inexistent'], $results[ConnectionTester::key($student->id, $broken->id)]);
        }
        $this->assertSame('falla', $results[ConnectionTester::key(null, $api->id)]['estat']);
        $this->assertNotSame('ok', $results[ConnectionTester::key($students[0]->id, $windows->id)]['estat']);
        $this->assertCount(7, $results);
    }

    public function test_overview_tests_a_row_and_shows_the_result(): void
    {
        $student = $this->module->students()->where('es_actiu', true)->orderBy('num_taula')->firstOrFail();

        Livewire::test(InventoryOverview::class, ['modul' => $this->module->id])
            ->call('testConnection', $student->id)
            ->assertSet('provesPendents', [])
            ->assertSet('connexio.'.ConnectionTester::key($student->id, $this->local->id).'.estat', 'ok')
            ->assertSee('Respon: Respon (ping)')
            ->assertNotified('Prova acabada: tots els equips responen');
    }

    public function test_column_test_covers_every_active_student(): void
    {
        $component = Livewire::test(InventoryOverview::class, ['modul' => $this->module->id])
            ->call('testConnection', null, $this->local->id);

        $this->assertCount($this->module->students()->where('es_actiu', true)->count(), $component->get('connexio'));
    }
}
