<?php

namespace Tests\Feature;

use App\Enums\Executor;
use App\Models\Action;
use App\Models\Check;
use App\Models\Module;
use App\Models\User;
use App\Runner\Ansible\Playbook;
use App\Runner\CodeValidator;
use App\Runner\ContextBuilder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Demo SMIX2: el mòdul del laboratori de xarxa (lab/lab.sh) i el mode plantilla. */
class Smix2LabSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedDemo(bool $lab = true): void
    {
        config(['heimdeye.lab' => ['enabled' => $lab, 'students' => 2, 'key' => '/keys/lab/id_ed25519']]);
        $this->seed(DemoSeeder::class);
    }

    private function module(): Module
    {
        return Module::where('codi', 'SMX2')->sole();
    }

    /** @return Collection<int, Check> */
    private function checks()
    {
        return Check::whereHas('task.block', fn ($q) => $q->where('modul_id', $this->module()->id))->with('currentVersion')->get();
    }

    public function test_the_lab_module_goes_with_the_tutorial(): void
    {
        $this->seedDemo();
        $module = $this->module();

        $this->assertSame('Tutorial', $module->academicYear->nom);
        $this->assertSame(['radia', 'vint'], $module->students()->orderBy('num_taula')->pluck('usuari')->all());
        $this->assertSame([21, 22], $module->students()->orderBy('num_taula')->pluck('x')->all());
        $this->assertSame(['router', 'client', 'dmz'], $module->hostTypes()->orderBy('posicio')->pluck('nom')->all());
        $this->assertSame([1, 2, 3], $module->blocks()->orderBy('numero')->pluck('numero')->all());
        $this->assertCount(15, $this->checks());
        $this->assertSame(6, Action::where('modul_id', $module->id)->count());
        $this->assertTrue($this->checks()->every(fn (Check $c) => filled($c->descripcio) && filled($c->info)));
    }

    public function test_seeding_twice_changes_nothing(): void
    {
        $this->seedDemo();
        $this->seedDemo();

        $this->assertCount(15, $this->checks());
        $this->assertSame(1, $this->checks()->first()->versions()->count());
        $this->assertSame(6, Action::where('modul_id', $this->module()->id)->count());
    }

    public function test_without_the_lab_the_demo_has_only_the_tutorial(): void
    {
        $this->seedDemo(lab: false);

        $this->assertFalse(Module::where('codi', 'SMX2')->exists());
    }

    public function test_the_code_passes_the_save_rules(): void
    {
        $this->seedDemo();
        $validator = app(CodeValidator::class);
        $module = $this->module();

        foreach ($this->checks() as $check) {
            $validator->validate($module, $check->executor, $check->currentVersion->codi);
            $this->assertSyntax($check->executor, $check->currentVersion->codi, $check->nom);
        }
        foreach (Action::where('modul_id', $module->id)->with('currentVersion')->get() as $action) {
            $validator->validate($module, $action->executor, $action->currentVersion->codi);
            $this->assertSyntax($action->executor, $action->currentVersion->codi, $action->nom);
            $this->assertNotEmpty($action->currentVersion->checks_verificacio, $action->nom);
        }
    }

    public function test_the_address_plan_comes_from_the_student_x(): void
    {
        $this->seedDemo();
        $module = $this->module();
        $student = $module->students()->orderBy('num_taula')->firstOrFail();
        $code = "# hosts: lab_router:lab_client:lab_dmz\necho \$HE_IP_WAN \$HE_IP_CLIENT \$HE_IP_DMZ \$HE_IP_HEIMDEYE_WAN \$HE_NOM_ZONA \$HE_MAC_CLIENT \$HE_IP_DHCP_INICI";

        $context = app(ContextBuilder::class)->build($module, $student, Executor::Ssh, $code, 15);

        $this->assertSame('203.0.113.21', $context->env['HE_IP_WAN']);
        $this->assertSame('10.21.1.100', $context->env['HE_IP_CLIENT']);
        $this->assertSame('10.21.2.10', $context->env['HE_IP_DMZ']);
        $this->assertSame('203.0.113.2', $context->env['HE_IP_HEIMDEYE_WAN']);
        $this->assertSame('radia.heimdeye.test', $context->env['HE_NOM_ZONA']);
        $this->assertSame('00:16:3e:00:01:21', $context->env['HE_MAC_CLIENT']);
        $this->assertSame('10.21.1.101', $context->env['HE_IP_DHCP_INICI']);
        $this->assertSame(['10.255.21.1', '10.255.21.10', '10.255.21.20'], collect($context->targets)->pluck('host')->sort()->values()->all());
        foreach ($context->targets as $target) {
            $this->assertSame('heimdeye', $target->user);
            $this->assertSame('/keys/lab/id_ed25519', $target->key);
        }
    }

    public function test_template_mode_logs_in_as_the_admin(): void
    {
        $this->seedDemo();
        config(['heimdeye.auth.mode' => 'plantilla']);
        $admin = User::where('email', config('heimdeye.admin.email'))->sole();

        $this->get('/admin')->assertRedirect();
        $this->assertAuthenticatedAs($admin);
    }

    public function test_other_modes_do_not_log_in_by_themselves(): void
    {
        $this->seedDemo();
        $this->get('/admin')->assertRedirect();
        $this->assertGuest();
    }

    private function assertSyntax(Executor $executor, string $code, string $name): void
    {
        if ($executor === Executor::Ansible) {
            $this->assertNotEmpty(Playbook::parse($code)->hostGroups(), $name);

            return;
        }

        $process = new Process(['bash', '-n']);
        $process->setInput($code)->run();
        $this->assertTrue($process->isSuccessful(), "{$name}: {$process->getErrorOutput()}");
    }
}
