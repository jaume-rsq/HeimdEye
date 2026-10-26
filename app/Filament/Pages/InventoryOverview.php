<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Students\StudentResource;
use App\Models\AcademicYear;
use App\Models\Module;
use App\Support\InventoryGrid;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
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

    public function mount(): void
    {
        $this->modul ??= $this->modules()->keys()->first();
    }

    /** Mòduls del curs actual (o de tots, si no n'hi ha cap marcat com a actual). */
    #[Computed]
    public function modules(): \Illuminate\Support\Collection
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

    public function studentUrl(int $id): string
    {
        return StudentResource::getUrl('edit', ['record' => $id, 'modul' => $this->modul]);
    }
}
