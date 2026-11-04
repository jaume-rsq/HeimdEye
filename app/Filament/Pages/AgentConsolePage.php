<?php

namespace App\Filament\Pages;

use App\Models\AgentInstance;
use App\Models\ConsoleMessage;
use App\Models\ConsoleSession;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Consola interactiva de les instàncies de l'agent (fase 3). El professor tria una instància
 * activa i obre una sessió: HeimdEye la deixa pendent, l'agent la recull (model pull) i obre un
 * bash persistent. L'entrada que s'escriu aquí i la sortida que torna l'agent viatgen com a
 * missatges, i es van refrescant amb polling. Tot queda registrat (eina docent, auditable).
 */
class AgentConsolePage extends Page
{
    protected static ?string $slug = 'consola';

    protected static ?string $title = 'Consola d\'instàncies';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCommandLine;

    protected static string|UnitEnum|null $navigationGroup = 'Accions';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.agent-console';

    #[Url]
    public ?int $instancia = null;

    public ?int $sessioId = null;

    public string $ordre = '';

    /** Id del darrer missatge ja mostrat, per demanar només els nous. */
    public int $cursor = 0;

    /** @var list<array{tipus: string, text: string}> */
    public array $linies = [];

    public bool $tancada = false;

    public function mount(): void
    {
        // Si es torna a una sessió activa d'aquesta instància, s'hi reconnecta.
        if ($this->instancia !== null) {
            $session = ConsoleSession::where('instancia_id', $this->instancia)
                ->where('estat', '!=', 'tancada')
                ->latest('id')
                ->first();
            if ($session !== null) {
                $this->attachTo($session);
            }
        }
    }

    /** Instàncies actives que el professor pot obrir. @return Collection<int, AgentInstance> */
    #[Computed]
    public function instances(): Collection
    {
        return AgentInstance::query()
            ->where('estat', 'activa')
            ->with('student')
            ->orderBy('hostname')
            ->get();
    }

    /** Obre una sessió nova per a la instància triada. */
    public function obrir(): void
    {
        $instance = AgentInstance::with('student')->find($this->instancia);
        if ($instance === null) {
            return;
        }

        $this->attachTo(self::openFor($instance));
    }

    /**
     * Crea (o reaprofita) una sessió de consola per a una instància. Si ja n'hi ha una d'oberta
     * (pendent o activa), la retorna en comptes de duplicar-la. La fa servir també la vista
     * d'instàncies de l'inventari, que hi redirigeix amb ?instancia=<id>.
     */
    public static function openFor(AgentInstance $instance): ConsoleSession
    {
        $open = ConsoleSession::where('instancia_id', $instance->id)
            ->where('estat', '!=', 'tancada')
            ->latest('id')
            ->first();
        if ($open !== null) {
            return $open;
        }

        return ConsoleSession::create([
            'alumne_id' => $instance->alumne_id,
            'instancia_id' => $instance->id,
            'rol_agent' => $instance->hostname,
            'obert_per' => auth()->id(),
            'estat' => 'pendent',
            'caduca_a' => now()->addSeconds((int) config('heimdeye.agent.console_pickup_seconds', 120)),
            'darrera_activitat' => now(),
        ]);
    }

    /** Envia una comanda (una línia) al bash de la instància. */
    public function enviar(): void
    {
        $session = $this->session();
        if ($session === null || ! $session->isOpen() || trim($this->ordre) === '') {
            return;
        }

        ConsoleMessage::create([
            'sessio_id' => $session->id,
            'direccio' => 'entrada',
            'contingut' => rtrim($this->ordre, "\n")."\n",
        ]);
        $session->update(['darrera_activitat' => now()]);
        $this->ordre = '';
        $this->refrescar();
    }

    /** Refresca el transcript amb els missatges nous (el crida el polling de la vista). */
    public function refrescar(): void
    {
        $session = $this->session();
        if ($session === null) {
            return;
        }
        $this->tancada = ! $session->isOpen();

        $nous = ConsoleMessage::query()
            ->where('sessio_id', $session->id)
            ->where('id', '>', $this->cursor)
            ->orderBy('id')
            ->get();
        foreach ($nous as $msg) {
            $this->linies[] = ['tipus' => $msg->direccio, 'text' => $msg->contingut];
            $this->cursor = $msg->id;
        }
    }

    /** Tanca la sessió oberta. */
    public function tancar(): void
    {
        $session = $this->session();
        if ($session !== null && $session->isOpen()) {
            $session->update(['estat' => 'tancada', 'data_fi' => now()]);
        }
        $this->tancada = true;
    }

    private function attachTo(ConsoleSession $session): void
    {
        $this->sessioId = $session->id;
        $this->instancia = $session->instancia_id;
        $this->cursor = 0;
        $this->linies = [];
        $this->tancada = ! $session->isOpen();
        $this->refrescar();
    }

    private function session(): ?ConsoleSession
    {
        return $this->sessioId === null ? null : ConsoleSession::find($this->sessioId);
    }
}
