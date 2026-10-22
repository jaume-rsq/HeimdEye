<?php

namespace Tests\Feature;

use App\Enums\CheckStatus;
use App\Models\Check;
use App\Models\CheckStudent;
use App\Models\Module;
use App\Models\Student;
use App\Runner\Ansible\InventoryBuilder;
use App\Runner\TempFiles;
use App\Support\VariableResolver;
use Database\Seeders\StudentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DomainModelTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_seeders_load_the_combined_module(): void
    {
        $module = Module::where('codi', '9991')->firstOrFail();

        $this->assertSame('SX+SI+AW', $module->nom);
        $this->assertSame(10, $module->students()->count());
        $this->assertSame(2, $module->blocks()->sole()->numero);
        $this->assertSame(3, Check::count());
        $this->assertTrue(Check::all()->every(fn (Check $c) => $c->currentVersion?->versio === 1));
    }

    public function test_active_flag_overrides_dates(): void
    {
        $check = new Check(['actiu_des' => '2026-11-02', 'actiu_fins' => '2026-11-30']);
        $before = Carbon::parse('2026-10-30');
        $inside = Carbon::parse('2026-11-10');

        $this->assertFalse($check->isActiveOn($before));
        $this->assertTrue($check->isActiveOn($inside));

        $check->es_actiu = true;
        $this->assertTrue($check->isActiveOn($before));

        $check->es_actiu = false;
        $this->assertFalse($check->isActiveOn($inside));
    }

    public function test_active_scope_matches_model_rule(): void
    {
        foreach (['2026-10-30', '2026-11-02', '2027-01-15'] as $day) {
            $day = Carbon::parse($day);
            $expected = Check::all()->filter->isActiveOn($day)->pluck('id')->sort()->values();

            $this->assertEquals($expected, Check::activeOn($day)->pluck('id')->sort()->values());
        }
    }

    public function test_punctuality_bonus_applies_only_before_deadline(): void
    {
        $check = new Check(['punts' => 10, 'bonus_puntualitat_pct' => 20, 'data_limit' => '2026-11-06 23:59:59']);

        $this->assertEquals(12, $check->pointsFor(Carbon::parse('2026-11-05 10:00')));
        $this->assertEquals(10, $check->pointsFor(Carbon::parse('2026-11-07 10:00')));
        $this->assertEquals(0, $check->pointsFor(null));
    }

    public function test_teacher_status_takes_priority(): void
    {
        $state = new CheckStudent(['estat_actual' => CheckStatus::Fail]);
        $this->assertFalse($state->isPassing());

        $state->estat_professor = true;
        $this->assertTrue($state->isPassing());

        $state->estat_actual = CheckStatus::Pass;
        $state->estat_professor = false;
        $this->assertFalse($state->isPassing());
    }

    public function test_resolver_builds_student_variables(): void
    {
        $student = Student::where('usuari', 'alumne03')->firstOrFail();
        $resolver = new VariableResolver;
        $vars = $resolver->resolve($student, $student->modules()->firstOrFail());

        $this->assertSame('23', $vars['x']);
        $this->assertSame('172.23.0.0/22', $vars['lan']);
        $this->assertSame('10.23.0.0/22', $vars['dmz']);
        $this->assertSame('198.51.100.51', $vars['pve_ip']);
        $this->assertSame('198.51.100.62', $vars['router_ip']);
        $this->assertSame('198.51.100.51', $resolver->toEnv($vars)['HE_PVE_IP']);

        $student->variables = [...$student->variables, 'router_ip' => '198.51.100.61'];
        $this->assertSame('198.51.100.61', $resolver->resolve($student, $student->modules()->first())['router_ip']);

        $this->assertSame(['missing_var'], $resolver->missing('{{ x }} {{ missing_var | host(1) }}', $vars));
    }

    public function test_host_filter(): void
    {
        $resolver = new VariableResolver;

        $this->assertSame('192.168.20.35', $resolver->host('192.168.20.32/28', 3));
        $this->assertSame('192.168.20.46', $resolver->host('192.168.20.32/28', -1));
        $this->assertSame('10.21.0.1', $resolver->host('10.21.1.7/22', 1));

        $this->expectException(\InvalidArgumentException::class);
        $resolver->host('192.168.20.32/28', 15);
    }

    public function test_student_seeder_reads_a_pasted_tab_separated_list(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'students');
        file_put_contents($path, "Xarxa\tTaula\tAlumne\teMail\talum\n192.168.20.192/28\tTaula11\tProva, Alumne\tprova@example.com\talum99\n");
        config(['heimdeye.students_csv' => $path]);

        $this->seed(StudentSeeder::class);
        unlink($path);

        $student = Student::where('usuari', 'prova')->firstOrFail();
        $this->assertSame(31, $student->x);
        $this->assertSame('Prova, Alumne', $student->nom_complet);
        $this->assertSame('Taula 11', $student->publicName());
    }

    public function test_inventory_gets_the_public_key_from_the_private_one(): void
    {
        config(['heimdeye.runner.ssh_pubkey_path' => '/home/jaume/.ssh/id_ed25519.pub']);
        $module = Module::where('codi', '9991')->firstOrFail();
        $builder = new InventoryBuilder(app(VariableResolver::class), new TempFiles);

        $inventory = $builder->build($module, [Student::where('usuari', 'alumne01')->firstOrFail()], $module->hostTypes);

        $this->assertSame('/home/jaume/.ssh/id_ed25519.pub', $inventory['all']['vars']['heimdeye_ssh_pubkey_file']);
        $this->assertSame('198.51.100.18', $inventory['all']['children']['estacio_windows']['hosts']['estacio01']['ansible_host']);
    }
}
