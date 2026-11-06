<?php

namespace Tests\Feature;

use App\Enums\Executor;
use App\Enums\Role;
use App\Filament\Resources\Actions\Pages\EditAction;
use App\Filament\Resources\Checks\Pages\EditCheck;
use App\Jobs\RunEditorTest;
use App\Models\AcademicYear;
use App\Models\Check;
use App\Models\Module;
use App\Models\Task;
use App\Models\User;
use App\Runner\CodeValidator;
use App\Runner\HostsRewriter;
use App\Support\Library;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/** Biblioteca de checks i accions (mòdul 0000): provar amb un mòdul de context i «Copiar a…». */
class LibraryTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Module $library;

    private Module $module;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->library = Library::module();
        $this->module = Module::where('codi', '9991')->firstOrFail();
        // Un professor que no imparteix la biblioteca també hi pot treballar.
        $this->teacher = User::create(['nom' => 'Prof', 'email' => 'prof@example.com', 'password' => 'secret', 'rol' => Role::Teacher, 'es_actiu' => true]);
        $this->teacher->modules()->attach($this->module);
    }

    private function libraryCheck(Executor $executor, string $code): Check
    {
        $task = Task::whereHas('block', fn ($q) => $q->where('modul_id', $this->library->id))->firstOrFail();
        $check = $task->checks()->create(['nom' => 'Port 22 obert', 'descripcio' => 'El port 22 escolta.', 'executor' => $executor, 'punts' => 2]);
        $check->versions()->create(['versio' => 1, 'codi' => $code, 'temps_max_seg' => 20]);

        return $check->fresh();
    }

    public function test_the_library_is_its_own_course_out_of_the_selectors(): void
    {
        $this->assertNotNull($this->library);
        $this->assertSame('0000', $this->library->codi);
        $this->assertTrue($this->library->isLibrary());
        $this->assertFalse($this->module->isLibrary());
        $this->assertNotContains($this->library->curs_id, AcademicYear::regular()->pluck('id')->all());
        $this->assertNotContains($this->library->id, Module::regular()->pluck('id')->all());
        $this->assertArrayNotHasKey($this->library->id, Library::targetModules());
        $this->assertTrue($this->library->blocks()->whereHas('tasks')->exists(), 'porta un bloc i una tasca per començar');
        $this->assertTrue($this->teacher->canEditCheck($this->libraryCheck(Executor::Local, 'true')));
    }

    public function test_host_groups_are_not_checked_in_the_library_but_all_still_is(): void
    {
        $validator = app(CodeValidator::class);
        $validator->validate($this->library, Executor::Ssh, "# hosts: qualsevol\ntrue");

        $this->expectException(InvalidArgumentException::class);
        $validator->validate($this->library, Executor::Ssh, "# hosts: all\ntrue");
    }

    public function test_the_hosts_rewriter_changes_only_where_the_code_goes(): void
    {
        $this->assertSame("# hosts: pve:vm_dns_bind\nss -ltn", HostsRewriter::rewrite(Executor::Ssh, "# hosts: web\nss -ltn", ['pve', 'vm_dns_bind']));
        $this->assertSame("#!/bin/bash\n# hosts: web\ntrue", HostsRewriter::rewrite(Executor::Agent, "#!/bin/bash\ntrue", ['web']));
        $this->assertSame('echo hola', HostsRewriter::rewrite(Executor::Local, 'echo hola', ['pve']));
        $this->assertSame("# hosts: web\ntrue", HostsRewriter::rewrite(Executor::Agent, "# hosts: web\ntrue", []));

        $playbook = "- hosts: pve\n  tasks:\n    - name: x\n      ansible.builtin.ping:\n- name: Segon\n  hosts: vm_router\n  tasks: []\n";
        $this->assertSame(
            "- hosts: vm_dns_bind\n  tasks:\n    - name: x\n      ansible.builtin.ping:\n- name: Segon\n  hosts: vm_dns_bind\n  tasks: []\n",
            HostsRewriter::rewrite(Executor::Ansible, $playbook, ['vm_dns_bind']),
        );
    }

    public function test_a_library_check_is_tested_with_a_context_module_and_other_equips(): void
    {
        Queue::fake();
        $this->actingAs($this->teacher);
        $check = $this->libraryCheck(Executor::Ssh, "# hosts: qualsevol\nss -ltn | grep -q ':22 '");
        $student = $this->module->students()->orderBy('num_taula')->firstOrFail();

        Livewire::test(EditCheck::class, ['record' => $check->id])
            ->callAction('provar', ['context' => $this->module->id, 'alumnes' => [(string) $student->id], 'equips' => ['vm_dns_bind']])
            ->assertHasNoActionErrors()
            ->assertSet('testResults.0.estat', 'en cua');

        Queue::assertPushed(RunEditorTest::class, fn (RunEditorTest $job) => $job->studentId === $student->id
            && $job->contextModuleId === $this->module->id
            && str_starts_with($job->version['codi'], '# hosts: vm_dns_bind'));
    }

    public function test_copy_to_creates_an_inactive_check_in_the_task_with_the_chosen_equips(): void
    {
        $this->actingAs($this->teacher);
        $check = $this->libraryCheck(Executor::Ssh, "# hosts: qualsevol\nss -ltn | grep -q ':22 '");
        $task = Task::whereHas('block', fn ($q) => $q->where('modul_id', $this->module->id))->firstOrFail();

        Livewire::test(EditCheck::class, ['record' => $check->id])
            ->callAction('copiarA', ['modul' => $this->module->id, 'tasca_id' => $task->id, 'equips' => ['pve'], 'nom' => 'SSH escolta al pve'])
            ->assertHasNoActionErrors();

        $copy = $task->checks()->where('nom', 'SSH escolta al pve')->sole();
        $this->assertFalse($copy->es_actiu);
        $this->assertSame(2, $copy->punts);
        $this->assertSame('El port 22 escolta.', $copy->descripcio);
        $this->assertSame(1, $copy->currentVersion->versio);
        $this->assertSame("# hosts: pve\nss -ltn | grep -q ':22 '", $copy->currentVersion->codi);
        $this->assertSame(1, $check->versions()->count(), 'l\'original no canvia');
    }

    public function test_copy_to_refuses_groups_the_target_module_does_not_have(): void
    {
        $this->actingAs($this->teacher);
        $check = $this->libraryCheck(Executor::Ssh, "# hosts: qualsevol\ntrue");
        $task = Task::whereHas('block', fn ($q) => $q->where('modul_id', $this->module->id))->firstOrFail();

        Livewire::test(EditCheck::class, ['record' => $check->id])
            ->callAction('copiarA', ['modul' => $this->module->id, 'tasca_id' => $task->id, 'equips' => [], 'nom' => 'Còpia'])
            ->assertNotified('No es pot copiar');
        $this->assertFalse($task->checks()->where('nom', 'Còpia')->exists());
    }

    public function test_a_library_action_is_copied_to_a_module(): void
    {
        $this->actingAs($this->teacher);
        $action = $this->library->actions()->create(['nom' => 'Instal·lar nmap', 'executor' => Executor::Agent]);
        $action->versions()->create(['versio' => 1, 'codi' => "# hosts: web\napt-get install -y nmap", 'temps_max_seg' => 300]);

        Livewire::test(EditAction::class, ['record' => $action->id])
            ->callAction('copiarA', ['modul' => $this->module->id, 'equips' => ['bd'], 'nom' => 'nmap a la bd'])
            ->assertHasNoActionErrors();

        $copy = $this->module->actions()->where('nom', 'nmap a la bd')->sole();
        $this->assertSame(Executor::Agent, $copy->executor);
        $this->assertSame("# hosts: bd\napt-get install -y nmap", $copy->currentVersion->codi);
    }
}
