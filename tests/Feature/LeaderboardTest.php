<?php

namespace Tests\Feature;

use App\Enums\CheckKind;
use App\Enums\CheckStatus;
use App\Enums\Role;
use App\Filament\Pages\LeaderboardPage;
use App\Models\Check;
use App\Models\CheckStudent;
use App\Models\Module;
use App\Models\Student;
use App\Models\User;
use App\Support\Leaderboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Leaderboard: punts, bonus, correcció del professor, checks que compten i ordre. */
class LeaderboardTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Module $module;

    /** @var list<Check> */
    private array $checks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->module = Module::where('codi', '9991')->firstOrFail();
        $task = $this->module->tasks()->firstOrFail();
        Check::whereIn('tasca_id', $this->module->tasks()->select('tasques.id'))->delete();
        $make = fn (array $a) => $task->checks()->create([...['executor' => 'local', 'tipus' => CheckKind::Required, 'punts' => 10, 'bonus_puntualitat_pct' => 0], ...$a]);
        $this->checks = [
            $make(['nom' => 'A', 'data_limit' => '2026-10-10 23:59', 'bonus_puntualitat_pct' => 50]),
            $make(['nom' => 'B', 'tipus' => CheckKind::Optional, 'punts' => 5]),
            $make(['nom' => 'Futur', 'actiu_des' => '2099-01-01']),
            $make(['nom' => 'Desactivat', 'es_actiu' => false]),
        ];
    }

    private function pass(Student $student, Check $check, ?string $when, ?bool $teacher = null): void
    {
        CheckStudent::create([
            'check_id' => $check->id, 'alumne_id' => $student->id,
            'estat_actual' => $when ? CheckStatus::Pass : CheckStatus::Fail,
            'estat_professor' => $teacher,
            'data_primer_assoliment' => $when,
        ]);
    }

    public function test_points_bonus_teacher_override_and_order(): void
    {
        [$s1, $s2, $s3, $s4] = $this->module->students()->orderBy('num_taula')->limit(4)->get()->all();
        $s2->update(['alies' => 'Fènix']);
        [$a, $b, $future, $off] = $this->checks;

        $this->pass($s1, $a, '2026-10-12 10:00');               // tard: 10
        $this->pass($s2, $a, '2026-10-05 10:00');               // a temps: 15
        $this->pass($s3, $a, '2026-10-12 09:00');               // tard: 10, però abans que s1
        $this->pass($s3, $b, null, teacher: true);               // professor, sense data: 5
        $this->pass($s3, $future, '2026-10-01 10:00');           // no compta
        $this->pass($s3, $off, '2026-10-01 10:00');              // no compta
        $this->pass($s4, $a, '2026-10-01 10:00', teacher: false); // el professor diu que no: 0

        $board = app(Leaderboard::class)->build($this->module, Carbon::parse('2026-10-26'));
        $rows = collect($board['rows'])->keyBy(fn ($r) => $r['student']->id);

        $this->assertSame(15.0, $rows[$s2->id]['punts']);
        $this->assertSame(15.0, $rows[$s3->id]['punts']);
        $this->assertSame(10.0, $rows[$s1->id]['punts']);
        $this->assertSame(0.0, $rows[$s4->id]['punts']);
        // Empat a 15: va davant qui hi va arribar abans (s2 el 5/10, s3 el 12/10).
        $this->assertSame([$s2->id, $s3->id, $s1->id], array_map(fn ($r) => $r['student']->id, array_slice($board['rows'], 0, 3)));
        $this->assertSame('Fènix', $rows[$s2->id]['nom_public']);
        $this->assertSame(sprintf('Taula %02d', $s1->num_taula), $rows[$s1->id]['nom_public']);
        $this->assertSame([1, 1, 100], [$rows[$s1->id]['obligatoris'], $rows[$s1->id]['obligatoris_total'], $rows[$s1->id]['percentatge']]);
        $this->assertSame(20.0, $board['punts_maxims']);
    }

    public function test_public_page_shows_aliases_but_never_names(): void
    {
        $student = $this->module->students()->orderBy('num_taula')->firstOrFail();
        $student->update(['alies' => 'Fènix']);

        $this->get('/leaderboard')
            ->assertOk()
            ->assertSee('href="/favicon-32x32.png?v=', false)
            ->assertSee('Fènix')
            ->assertSee('Taula 02')
            ->assertDontSee($student->nom_complet)
            ->assertDontSee($student->usuari);
    }

    public function test_panel_page_shows_names(): void
    {
        $this->actingAs(User::create(['nom' => 'Prof', 'email' => 'prof@example.com', 'password' => 'secret', 'rol' => Role::Teacher, 'es_actiu' => true]));
        $student = $this->module->students()->orderBy('num_taula')->firstOrFail();

        Livewire::test(LeaderboardPage::class)->assertSee($student->nom_complet)->assertSee('Versió pública');
        $this->get(LeaderboardPage::getUrl())->assertOk()->assertSee('href="/favicon.ico?v=', false);
    }
}
