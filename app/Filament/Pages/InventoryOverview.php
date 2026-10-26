<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Students\StudentResource;
use App\Jobs\TestConnection;
use App\Models\AcademicYear;
use App\Models\HostType;
use App\Models\Module;
use App\Support\ConnectionTester;
use App\Support\InventoryGrid;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Throwable;
use UnitEnum;

/**
 * Inventari d'un mòdul d'un cop d'ull: alumnes × equips (nom i adreça calculats)
 * o alumnes × variables (amb l'origen de cada valor), amb els problemes marcats.
 */
class InventoryOverview extends Page
{
    protected static ?string $slug = 'inventari';

    protected static ?string $title = 'Vista de conjunt';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Inventari';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.inventory-overview';

    protected Width|string|null $maxContentWidth = Width::Full;

    #[Url]
    public ?int $modul = null;

    #[Url]
    public string $vista = 'equips';

    #[Url]
    public bool $problemes = false;

    /** @var array<string, array{estat: string, missatge: string}> resultat de les proves de connexió, per equip */
    public array $connexio = [];

    /** @var array<string, list<string>> proves a la cua: clau => equips que prova */
    public array $provesPendents = [];

    public function mount(): void
    {
        $this->modul ??= $this->modules()->keys()->first();
    }

    public function updatedModul(): void
    {
        $this->connexio = [];
        $this->provesPendents = [];
    }

    /** Mòduls del curs actual (o de tots, si no n'hi ha cap marcat com a actual). */
    #[Computed]
    public function modules(): Collection
    {
        $year = AcademicYear::where('es_actual', true)->first();

        return Module::query()
            ->when($year, fn ($q) => $q->where('curs_id', $year->id))
            ->orderBy('codi')
            ->get()
            ->mapWithKeys(fn (Module $m) => [$m->id => trim("{$m->codi} {$m->nom}")]);
    }

    #[Computed]
    public function grid(): ?array
    {
        $module = $this->modul ? Module::find($this->modul) : null;

        return $module ? app(InventoryGrid::class)->build($module) : null;
    }

    /**
     * Prova de connexió: un equip (alumne i tipus), un tipus per a tots els alumnes (o un equip global)
     * o tots els equips d'un alumne. No es desa res.
     */
    public function testConnection(?int $studentId = null, ?int $typeId = null): void
    {
        $module = Module::find($this->modul);
        if ($module === null || ($studentId === null && $typeId === null)) {
            return;
        }

        $types = $module->hostTypes()
            ->when($typeId, fn ($q) => $q->whereKey($typeId), fn ($q) => $q->where('abast', '!=', 'global'))
            ->orderBy('posicio')
            ->get();
        $students = $module->students()->where('es_actiu', true)
            ->when($studentId, fn ($q) => $q->whereKey($studentId))
            ->orderBy('num_taula')
            ->get();
        if ($types->isEmpty()) {
            return;
        }

        $cells = [];
        foreach ($types as $type) {
            foreach ($type->isGlobal() ? [null] : $students->pluck('id') as $id) {
                $cells[] = ConnectionTester::key($id, $type->id);
            }
        }
        foreach ($cells as $cell) {
            $this->connexio[$cell] = ['estat' => 'pendent', 'missatge' => 'Provant...'];
        }

        $key = (string) Str::uuid();
        $this->provesPendents[$key] = $cells;
        try {
            dispatch(new TestConnection($key, $module->id, $students->pluck('id')->all(), $types->pluck('id')->all()));
        } catch (Throwable) {
            // Amb la cua síncrona l'error ja és a la memòria cau (TestConnection::failed).
        }
        $this->refreshConnection();
    }

    /** Recull les proves acabades (la vista ho crida cada 2 s mentre n'hi ha a la cua). */
    public function refreshConnection(): void
    {
        foreach ($this->provesPendents as $key => $cells) {
            $result = Cache::pull(TestConnection::cacheKey($key));
            if ($result === null) {
                continue;
            }
            unset($this->provesPendents[$key]);
            foreach ($cells as $cell) {
                $this->connexio[$cell] = $result['resultats'][$cell] ?? ['estat' => 'error', 'missatge' => $result['error'] ?? 'Sense resultat'];
            }
            if ($this->provesPendents === []) {
                $bad = collect($this->connexio)->where('estat', '!=', 'ok')->count();
                Notification::make()
                    ->{$bad ? 'warning' : 'success'}()
                    ->title($bad ? "Prova acabada: {$bad} equips no responen bé" : 'Prova acabada: tots els equips responen')
                    ->send();
            }
        }
    }

    /** Punt de color amb l'estat de la prova de connexió d'un equip. */
    public function connectionDot(?int $studentId, HostType $type): ?array
    {
        $result = $this->connexio[ConnectionTester::key($studentId, $type->id)] ?? null;

        return $result === null ? null : [
            ...$result,
            'color' => match ($result['estat']) {
                'ok' => 'rgb(22,163,74)',
                'no_respon' => 'rgb(220,38,38)',
                'falla' => 'rgb(234,88,12)',
                'pendent' => 'rgb(156,163,175)',
                default => 'rgb(107,114,128)',
            },
            'etiqueta' => match ($result['estat']) {
                'ok' => 'Respon',
                'no_respon' => 'No respon',
                'falla' => 'Respon però falla',
                'pendent' => 'Provant...',
                default => 'No s\'ha pogut provar',
            },
        ];
    }

    public function studentUrl(int $id): string
    {
        return StudentResource::getUrl('edit', ['record' => $id, 'modul' => $this->modul]);
    }
}
