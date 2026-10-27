<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Student\Pages\MyProgress;
use App\Models\Check;
use App\Models\Module;
use App\Models\Student;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Panell dels alumnes: cadascú veu només el seu seguiment i no pot canviar res. */
class StudentPanelTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Module $module;

    private Student $student;

    private Student $other;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->module = Module::where('codi', '9991')->firstOrFail();
        [$this->student, $this->other] = $this->module->students()->orderBy('num_taula')->take(2)->get()->all();
        $this->user = User::create(['nom' => $this->student->nom_complet, 'email' => 'alumne1@example.com', 'password' => 'secret', 'rol' => Role::Student, 'es_actiu' => true]);
        $this->student->update(['usuari_id' => $this->user->id]);
        Filament::setCurrentPanel(Filament::getPanel('alumne'));
    }

    public function test_a_student_sees_only_their_own_progress(): void
    {
        $this->actingAs($this->user)->get('/alumne')->assertRedirect(MyProgress::getUrl());
        $this->get(MyProgress::getUrl())
            ->assertOk()
            ->assertSee('El meu seguiment')
            ->assertSee($this->student->nom_complet)
            ->assertDontSee($this->other->nom_complet)
            ->assertDontSee('Verificar')
            ->assertDontSee('Corregir');
    }

    public function test_the_url_or_the_component_cannot_switch_to_another_student(): void
    {
        $this->actingAs($this->user);

        Livewire::withQueryParams(['alumne' => $this->other->id])
            ->test(MyProgress::class)
            ->assertSet('alumne', $this->student->id)
            ->set('alumne', $this->other->id)
            ->assertForbidden();
    }

    public function test_a_student_cannot_verify_or_correct(): void
    {
        $check = Check::whereIn('tasca_id', $this->module->tasks()->select('tasques.id'))->firstOrFail();
        $this->actingAs($this->user);

        Livewire::test(MyProgress::class)->call('verify', $check->id)->assertForbidden();
        Livewire::test(MyProgress::class)->assertActionHidden('correct');
        Livewire::test(MyProgress::class)
            ->mountAction('info', ['check' => $check->id])
            ->assertMountedActionModalSee('Codi del check amb les teves dades');
    }

    public function test_students_who_open_the_teachers_panel_go_to_theirs(): void
    {
        $this->actingAs($this->user)->get('/admin')->assertRedirect('/alumne');
    }

    public function test_teachers_cannot_enter_the_student_panel(): void
    {
        $teacher = User::create(['nom' => 'Prof', 'email' => 'prof@example.com', 'password' => 'secret', 'rol' => Role::Teacher, 'es_actiu' => true]);

        $this->actingAs($teacher)->get(MyProgress::getUrl())->assertForbidden();
    }
}
