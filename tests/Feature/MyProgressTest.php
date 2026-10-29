<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\StudentTracking;
use App\Filament\Student\Pages\MyProgress;
use App\Jobs\RunCheck;
use App\Models\Check;
use App\Models\Module;
use App\Models\Student;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** Seguiment de l'alumne: només verifica checks actius, d'un en un, per a ell i un cop cada 10 minuts. */
class MyProgressTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Student $student;

    private Check $check;

    protected function setUp(): void
    {
        parent::setUp();

        $module = Module::where('codi', '9991')->firstOrFail();
        $this->student = $module->students()->orderBy('num_taula')->firstOrFail();
        $this->check = Check::whereIn('tasca_id', $module->tasks()->select('tasques.id'))->orderBy('id')->firstOrFail();
        $this->check->update(['es_actiu' => true]);

        $user = User::create(['nom' => 'Alumne', 'email' => 'alumne@example.com', 'rol' => Role::Student, 'es_actiu' => true]);
        $this->student->update(['usuari_id' => $user->id]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('alumne'));
        Queue::fake();
    }

    private function page()
    {
        return Livewire::test(MyProgress::class, ['tasca' => $this->check->tasca_id]);
    }

    public function test_a_student_verifies_one_active_check_for_themselves(): void
    {
        $this->page()
            ->assertSee('Verificar ara (un cop cada 10 minuts)')
            ->call('verify', $this->check->id)
            ->assertNotified('Check a la cua')
            ->assertSee('es posa al dia sola');

        Queue::assertPushed(RunCheck::class, 1);
        Queue::assertPushed(RunCheck::class, fn (RunCheck $job) => $job->student->is($this->student) && $job->check->is($this->check));
    }

    public function test_the_same_check_waits_ten_minutes(): void
    {
        $this->page()->call('verify', $this->check->id);
        $page = $this->page();
        $this->assertSame(10, $page->instance()->verifyWaitMinutes($this->check));
        $page->assertSee('tornar a verificar-lo', false)
            ->call('verify', $this->check->id)
            ->assertNotified('Encara no');
        Queue::assertPushed(RunCheck::class, 1);

        $this->travel(11)->minutes();
        $this->page()->call('verify', $this->check->id);
        Queue::assertPushed(RunCheck::class, 2);
    }

    public function test_only_single_active_checks_of_their_modules(): void
    {
        $this->page()->call('verify', null, $this->check->tasca_id)->assertForbidden();
        $this->page()->call('verify', null, null, $this->check->task->bloc_id)->assertForbidden();
        $this->page()->call('verify')->assertForbidden();

        $other = Check::whereNotIn('tasca_id', $this->check->task->block->module->tasks()->select('tasques.id'))->first();
        if ($other) {
            $this->page()->call('verify', $other->id)->assertForbidden();
        }

        $this->check->update(['es_actiu' => false]);
        $page = $this->page();
        $this->assertFalse($page->instance()->canVerify($this->check->fresh()));
        $page->call('verify', $this->check->id)->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_teachers_have_no_limit(): void
    {
        $teacher = User::create(['nom' => 'Prof', 'email' => 'prof@example.com', 'password' => 'secret', 'rol' => Role::Admin, 'es_actiu' => true]);
        $this->actingAs($teacher);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $page = Livewire::test(StudentTracking::class, ['alumne' => $this->student->id]);
        $page->call('verify', $this->check->id)->call('verify', $this->check->id);
        Queue::assertPushed(RunCheck::class, 2);
    }
}
