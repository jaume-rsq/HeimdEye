<?php

namespace Tests\Feature;

use App\Enums\CheckStatus;
use App\Models\Check;
use App\Models\CheckStudent;
use App\Models\Module;
use App\Models\Student;
use App\Support\VariableResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DomainModelTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_seeders_load_the_smix2_module(): void
    {
        $module = Module::where('name', 'SMIX2 Infraestructura')->firstOrFail();

        $this->assertSame(10, $module->students()->count());
        $this->assertSame(8, $module->blocks()->sole()->number);
        $this->assertSame(8, Check::count());
        $this->assertTrue(Check::all()->every(fn (Check $c) => $c->currentVersion?->version === 1));
    }

    public function test_active_flag_overrides_dates(): void
    {
        $check = new Check(['active_from' => '2026-11-02', 'active_to' => '2026-11-30']);
        $before = Carbon::parse('2026-10-30');
        $inside = Carbon::parse('2026-11-10');

        $this->assertFalse($check->isActiveOn($before));
        $this->assertTrue($check->isActiveOn($inside));

        $check->active = true;
        $this->assertTrue($check->isActiveOn($before));

        $check->active = false;
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
        $check = new Check(['points' => 10, 'punctuality_bonus_pct' => 20, 'deadline' => '2026-11-06 23:59:59']);

        $this->assertEquals(12, $check->pointsFor(Carbon::parse('2026-11-05 10:00')));
        $this->assertEquals(10, $check->pointsFor(Carbon::parse('2026-11-07 10:00')));
        $this->assertEquals(0, $check->pointsFor(null));
    }

    public function test_teacher_status_takes_priority(): void
    {
        $state = new CheckStudent(['current_status' => CheckStatus::Fail]);
        $this->assertFalse($state->isPassing());

        $state->teacher_status = true;
        $this->assertTrue($state->isPassing());

        $state->current_status = CheckStatus::Pass;
        $state->teacher_status = false;
        $this->assertFalse($state->isPassing());
    }

    public function test_resolver_builds_student_variables(): void
    {
        $student = Student::where('username', 'alumne03')->firstOrFail();
        $resolver = new VariableResolver;
        $vars = $resolver->resolve($student, $student->modules()->firstOrFail());

        $this->assertSame('10.23.0.11', $vars['ns1']);
        $this->assertSame('alumne03.smix2.julioantonio.cat', $vars['subdomain']);
        $this->assertSame('10.23.0.11', $resolver->toEnv($vars)['HE_NS1']);

        $student->vars = [...$student->vars, 'ns1' => '10.23.0.53'];
        $this->assertSame('10.23.0.53', $resolver->resolve($student, $student->modules()->first())['ns1']);

        $this->assertSame(['missing_var'], $resolver->missing('{{ ns1 }} {{missing_var}}', $vars));
    }
}
