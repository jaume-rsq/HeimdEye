<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Student\Pages\MyAgent;
use App\Models\Module;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

/** La pàgina «El meu agent» del panell de l'alumne: la seva comanda d'instal·lació i el seu token. */
class StudentAgentPageTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $user;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->student = Module::where('codi', '9991')->firstOrFail()
            ->students()->orderBy('num_taula')->firstOrFail();
        $this->user = User::create(['nom' => 'Alumne', 'email' => 'alumne-test@example.com', 'password' => 'secret', 'rol' => Role::Student, 'es_actiu' => true]);
        $this->student->forceFill(['usuari_id' => $this->user->id])->save();
        $this->actingAs($this->user);
    }

    public function test_it_shows_the_students_own_install_command_with_the_token(): void
    {
        config(['heimdeye.agent.download_base' => 'https://example.test/latest', 'app.url' => 'https://heimdeye.test']);
        $token = $this->student->regenerateAgentToken();

        Livewire::test(MyAgent::class)
            ->assertOk()
            ->assertSee('curl -fsSL -o heimdeye-agent https://example.test/latest/heimdeye-agent-linux-$A', escape: false)
            ->assertSee("--token {$token} --hostname web", escape: false)
            // Explica la funcionalitat i els dos paràmetres que l'alumne pot canviar.
            ->assertSee('Els dos paràmetres que pots canviar')
            ->assertSee('--hostname', escape: false)
            ->assertSee('--user root', escape: false)
            ->assertSee('ssm-user');

        $this->assertTrue(MyAgent::shouldRegisterNavigation());
    }

    public function test_without_a_token_the_page_asks_to_request_one_and_is_hidden(): void
    {
        $this->student->forceFill(['token_agent' => null])->save();

        $this->assertFalse(MyAgent::shouldRegisterNavigation());

        Livewire::test(MyAgent::class)
            ->assertOk()
            ->assertSee('Encara no tens cap token')
            ->assertDontSee('heimdeye-agent install');
    }

    public function test_it_lists_the_students_registered_instances(): void
    {
        $this->student->regenerateAgentToken();
        $this->student->agentInstances()->create(['hostname' => 'aws', 'estat' => 'activa', 'ip_publica' => '203.0.113.7', 'darrer_batec' => now()]);

        Livewire::test(MyAgent::class)
            ->assertSee('Les teves instàncies')
            ->assertSee('aws')
            ->assertSee('203.0.113.7');
    }

    public function test_the_navigation_reflects_the_logged_in_student(): void
    {
        Auth::logout();
        $this->assertFalse(MyAgent::shouldRegisterNavigation());
    }
}
