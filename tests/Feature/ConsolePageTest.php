<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\AgentConsolePage;
use App\Models\AgentInstance;
use App\Models\ConsoleMessage;
use App\Models\ConsoleSession;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Pàgina de consola interactiva: obrir sessió, enviar comandes, veure la sortida i tancar. */
class ConsolePageTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $teacher;

    private AgentInstance $instance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = User::create(['nom' => 'Prof', 'email' => 'prof@example.com', 'password' => 'secret', 'rol' => Role::Teacher, 'es_actiu' => true]);
        $student = Student::where('usuari', 'alumne01')->firstOrFail();
        $this->instance = AgentInstance::create(['alumne_id' => $student->id, 'hostname' => 'web', 'estat' => 'activa', 'darrer_batec' => now()]);
        $this->actingAs($this->teacher);
    }

    public function test_opening_a_session_queues_it_pending_and_sending_records_input(): void
    {
        $component = Livewire::test(AgentConsolePage::class)
            ->set('instancia', $this->instance->id)
            ->call('obrir');

        $session = ConsoleSession::sole();
        $this->assertSame('pendent', $session->estat);
        $this->assertSame($this->teacher->id, $session->obert_per);
        $component->assertSet('sessioId', $session->id);

        $component->set('ordre', 'ls -la')->call('enviar')->assertSet('ordre', '');
        $entrada = ConsoleMessage::where('direccio', 'entrada')->sole();
        $this->assertSame("ls -la\n", $entrada->contingut);
    }

    public function test_output_appears_in_the_transcript_on_refresh(): void
    {
        $session = ConsoleSession::create(['alumne_id' => $this->instance->alumne_id, 'instancia_id' => $this->instance->id, 'rol_agent' => 'web', 'estat' => 'activa', 'darrera_activitat' => now()]);
        ConsoleMessage::create(['sessio_id' => $session->id, 'direccio' => 'sortida', 'contingut' => "total 0\n"]);

        Livewire::test(AgentConsolePage::class, ['instancia' => $this->instance->id])
            ->call('refrescar')
            ->assertSee('total 0');
    }

    public function test_closing_the_session_marks_it_closed(): void
    {
        Livewire::test(AgentConsolePage::class)
            ->set('instancia', $this->instance->id)
            ->call('obrir')
            ->call('tancar')
            ->assertSet('tancada', true);

        $this->assertSame('tancada', ConsoleSession::sole()->estat);
    }
}
