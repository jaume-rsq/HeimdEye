<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\ManagesHostTypes;
use App\Filament\Pages\Concerns\ManagesLevels;
use App\Filament\Pages\Concerns\ManagesSecrets;
use App\Filament\Pages\Concerns\ManagesStudentPanel;
use App\Filament\Pages\Concerns\ManagesVariables;
use App\Filament\Pages\Concerns\RemembersScope;
use App\Jobs\TestConnection;
use App\Models\AcademicYear;
use App\Models\Group;
use App\Models\HostType;
use App\Models\Module;
use App\Models\Student;
use App\Support\ConnectionTester;
use App\Support\InventoryGrid;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Throwable;
use UnitEnum;

/**
 * Inventari d'un mòdul en una sola pantalla, amb pestanyes:
 * - Alumnes: alumnes × equips (nom i adreça calculats) o alumnes × variables (amb l'origen de
 *   cada valor), amb els problemes marcats i la prova de connexió; un clic a un alumne obre el
 *   seu panell lateral (què rep i d'on surt);
 * - Equips, Variables i Secrets: tot el que veu el mòdul (global, del curs i del mòdul), editable al seu nivell.
 * Sense mòdul triat (modul = 0) només hi ha els comuns: globals i del curs actual.
 */
class InventoryOverview extends Page
{
    use RemembersScope;

    /** Un alumne a la URL (el panell lateral des de la fitxa) també mana. */
    protected function scopeLinkProperties(): array
    {
        return ['curs', 'grup', 'modul', 'alumne'];
    }

    use ManagesHostTypes;
    use ManagesLevels;
    use ManagesSecrets;
    use ManagesStudentPanel;
    use ManagesVariables;

    protected static ?string $slug = 'inventari';

    protected static ?string $title = 'Inventari';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Inventari';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.inventory-overview';

    protected Width|string|null $maxContentWidth = Width::Full;

    #[Url]
    public ?int $curs = null;

    /** Grup-classe: limita els mòduls i els alumnes. Sempre n'hi ha un (si el curs en té). */
    #[Url]
    public ?int $grup = null;

    #[Url]
    public ?int $modul = null;

    /** alumnes, equips, variables o secrets */
    #[Url]
    public string $pestanya = 'alumnes';

    #[Url]
    public string $vista = 'equips';

    #[Url]
    public bool $problemes = false;

    /** Alumne amb el panell lateral obert en entrar (enllaç des de la fitxa). */
    #[Url]
    public ?int $alumne = null;

    /** @var array<string, array{estat: string, missatge: string}> resultat de les proves de connexió, per equip */
    public array $connexio = [];

    /** @var array<string, list<string>> proves a la cua: clau => equips que prova */
    public array $provesPendents = [];

    public function mount(): void
    {
        $this->restoreScope();
        // Un mòdul a la URL porta el seu curs; si no, el curs actual (o el més recent).
        $linked = $this->modul ? Module::find($this->modul) : null;
        if ($linked) {
            $this->curs = $linked->curs_id;
        }
        if ($this->curs === null || ! $this->courses()->has($this->curs)) {
            $this->curs = AcademicYear::where('es_actual', true)->value('id') ?? $this->courses()->keys()->first();
        }
        // Un alumne a l'enllaç porta el seu grup.
        $linkedStudent = $this->alumne ? Student::find($this->alumne) : null;
        if ($linkedStudent?->grup_id && $this->groups()->has($linkedStudent->grup_id)) {
            $this->grup = $linkedStudent->grup_id;
        }
        $this->ensureGroup();
        if ($this->modul !== 0 && ($this->modul === null || ! $this->modules()->has($this->modul))) {
            $this->modul = $this->modules()->keys()->first();
        }
        if ($this->pestanya === 'secrets' && ! $this->canSeeSecrets()) {
            $this->pestanya = 'variables';
        }
        if ($this->alumne && $this->module?->students()->whereKey($this->alumne)->exists()) {
            $this->defaultAction = 'student';
            $this->defaultActionArguments = ['alumne' => $this->alumne];
        }
        $this->alumne = null;
    }

    public function updatedModul(): void
    {
        $this->connexio = [];
        $this->provesPendents = [];
        unset($this->module, $this->course, $this->grid);
    }

    /** Capçalera: el títol amb les pestanyes al costat i, a la dreta, el curs i el mòdul. */
    public function getHeader(): ?View
    {
        return view('filament.partials.inventory-header', ['title' => static::$title]);
    }

    public function updatedCurs(): void
    {
        unset($this->groups);
        $this->grup = null;
        $this->ensureGroup();
        unset($this->modules);
        $this->modul = $this->modules()->keys()->first() ?? 0;
        $this->updatedModul();
    }

    public function updatedGrup(): void
    {
        $this->ensureGroup();
        unset($this->modules);
        if ($this->modul !== 0 && ! $this->modules()->has($this->modul)) {
            $this->modul = $this->modules()->keys()->first() ?? 0;
        }
        $this->updatedModul();
    }

    /** Com al leaderboard i al seguiment, sempre hi ha un grup triat: si no és vàlid, el primer. */
    private function ensureGroup(): void
    {
        if ($this->grup === null || ! $this->groups()->has($this->grup)) {
            $this->grup = $this->groups()->keys()->first();
        }
    }

    /** @return Collection<int, string> grups del curs triat */
    #[Computed]
    public function groups(): Collection
    {
        return Group::where('curs_id', $this->curs)->orderBy('codi')->get()
            ->mapWithKeys(fn (Group $g) => [$g->id => $g->label()]);
    }

    /** @return Collection<int, string> cursos, el més recent primer */
    #[Computed]
    public function courses(): Collection
    {
        return AcademicYear::regular()->orderByDesc('data_inici')->pluck('nom', 'id');
    }

    /** Mòduls del curs triat (amb un grup, els que hi tenen algun alumne). */
    #[Computed]
    public function modules(): Collection
    {
        return Module::query()
            ->when($this->curs, fn ($q) => $q->where('curs_id', $this->curs))
            ->withGroup($this->grup)
            ->orderBy('codi')
            ->get()
            ->mapWithKeys(fn (Module $m) => [$m->id => trim("{$m->codi} {$m->nom}")]);
    }

    #[Computed]
    public function grid(): ?array
    {
        $grid = $this->module ? app(InventoryGrid::class)->build($this->module) : null;
        if ($grid && $this->grup) {
            $grid['rows'] = array_values(array_filter($grid['rows'], fn (array $row) => $row['student']->grup_id === $this->grup));
        }

        return $grid;
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

        $types = $module->visibleHostTypes()
            ->when($typeId, fn ($types) => $types->where('id', $typeId), fn ($types) => $types->reject->isUnique())
            ->values();
        $students = $module->students()->where('es_actiu', true)
            ->when($this->grup, fn ($q) => $q->where('grup_id', $this->grup))
            ->when($studentId, fn ($q) => $q->whereKey($studentId))
            ->orderBy('num_taula')
            ->get();
        if ($types->isEmpty()) {
            return;
        }

        $cells = [];
        foreach ($types as $type) {
            foreach ($type->isUnique() ? [null] : $students->pluck('id') as $id) {
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
}
