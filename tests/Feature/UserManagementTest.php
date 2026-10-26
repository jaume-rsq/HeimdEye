<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\Module;
use App\Models\Student;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/** Usuaris i rols: només per a administradors, i ningú es pot treure l'accés a si mateix. */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create(['nom' => 'Admin', 'email' => 'cap@example.com', 'password' => 'secret', 'rol' => Role::Admin, 'es_actiu' => true]);
        $this->actingAs($this->admin);
    }

    public function test_teachers_cannot_manage_users(): void
    {
        $this->actingAs(User::create(['nom' => 'Prof', 'email' => 'prof@example.com', 'password' => 'secret', 'rol' => Role::Teacher, 'es_actiu' => true]));

        $this->get('/admin/usuaris')->assertForbidden();
    }

    public function test_admin_sees_the_page(): void
    {
        $this->get('/admin/usuaris')->assertOk()->assertSee('Usuaris i rols');
    }

    public function test_admin_creates_a_teacher_with_modules(): void
    {
        $module = Module::where('codi', '9991')->firstOrFail();

        Livewire::test(ManageUsers::class)
            ->callAction('create', ['nom' => 'Nova Profe', 'email' => 'nova@example.com', 'rol' => 'professor', 'es_actiu' => true, 'password' => 'una-contrasenya', 'modules' => [$module->id]])
            ->assertHasNoActionErrors();

        $user = User::where('email', 'nova@example.com')->firstOrFail();
        $this->assertSame(Role::Teacher, $user->rol);
        $this->assertTrue($user->modules->contains($module));
        $this->assertTrue(Hash::check('una-contrasenya', $user->password));
    }

    public function test_list_shows_staff_by_default_and_hides_students(): void
    {
        $student = Student::with('user')->firstOrFail()->user;

        Livewire::test(ManageUsers::class)
            ->assertCanSeeTableRecords([$this->admin])
            ->assertCanNotSeeTableRecords([$student]);
    }

    public function test_nobody_locks_themselves_out_or_deletes_students(): void
    {
        $studentUser = Student::with('user')->firstOrFail()->user;

        Livewire::test(ManageUsers::class)
            ->assertActionDisabled(TestAction::make('delete')->table($this->admin))
            ->mountAction(TestAction::make('edit')->table($this->admin))
            ->assertActionDataSet(['rol' => 'admin'])
            ->setActionData(['rol' => 'professor', 'es_actiu' => false, 'nom' => 'Admin 2'])
            ->callMountedAction();

        $this->admin->refresh();
        $this->assertSame('Admin 2', $this->admin->nom);
        $this->assertSame(Role::Admin, $this->admin->rol);
        $this->assertTrue($this->admin->es_actiu);

        Livewire::test(ManageUsers::class)
            ->filterTable('rol', ['alumne'])
            ->assertActionDisabled(TestAction::make('delete')->table($studentUser));
    }
}
