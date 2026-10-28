<?php

namespace App\Filament\Resources\Students\Pages;

use App\Filament\Pages\InventoryOverview;
use App\Filament\Pages\StudentTracking;
use App\Filament\Resources\Students\StudentResource;
use App\Models\ActionRun;
use App\Models\Module;
use App\Models\Student;
use App\Runner\Ansible\InventoryBuilder;
use App\Runner\Masker;
use App\Runner\TempFiles;
use App\Support\InventoryGrid;
use App\Support\VariableResolver;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * Fitxa d'un alumne: les dades, les variables pròpies i l'inventari calculat
 * (variables amb origen, equips, inventari Ansible i activitat) per a un dels seus mòduls.
 *
 * @property Student $record
 */
class EditStudent extends EditRecord
{
    protected static string $resource = StudentResource::class;

    #[Url]
    public ?int $modul = null;

    #[Url]
    public string $pestanya = 'variables';

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if (! $this->record->modules->contains('id', $this->modul)) {
            $this->modul = $this->record->modules->first()?->id;
        }
    }

    public function getTitle(): string
    {
        return sprintf('%02d · %s', $this->record->num_taula, $this->record->nom_complet);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('seguiment')
                ->label('Seguiment')
                ->icon(Heroicon::OutlinedPresentationChartLine)
                ->color('gray')
                ->url(fn () => StudentTracking::getUrl(['alumne' => $this->record->id])),
            Action::make('conjunt')
                ->label('Inventari')
                ->icon(Heroicon::OutlinedSquares2x2)
                ->color('gray')
                ->url(fn () => InventoryOverview::getUrl(['modul' => $this->modul])),
            DeleteAction::make()
                ->modalDescription(fn () => 'S\'esborraran totes les execucions de checks i accions d\'aquest alumne i els seus assoliments ('
                    .$this->record->runs()->count().' execucions de checks). Si només ha deixat el curs, millor marca\'l com a inactiu. No es pot desfer.'),
        ];
    }

    /** @return Collection<int, Module> */
    public function inventoryModules(): Collection
    {
        return $this->record->modules;
    }

    /**
     * Dades de la secció "Inventari calculat" per al mòdul triat.
     *
     * @return array<string, mixed>|null
     */
    public function inventory(): ?array
    {
        $module = $this->modul ? Module::find($this->modul) : null;
        if ($module === null) {
            return null;
        }

        $student = $this->record->fresh(['user']);
        $grid = app(InventoryGrid::class)->build($module, collect([$student]));
        $row = $grid['rows'][0];

        $types = $module->visibleHostTypes();
        $files = new TempFiles;
        try {
            $builder = new InventoryBuilder(app(VariableResolver::class), $files);
            $json = json_encode($builder->build($module, [$student], $types->reject->isUnique()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $ansible = Masker::mask($json, $builder->mask());
        } catch (\Throwable $e) {
            $ansible = 'No s\'ha pogut generar: '.$e->getMessage();
        } finally {
            $files->cleanup();
        }

        return [
            'vars' => $row['vars'],
            'access' => $module->access,
            'hosts' => $types->reject->isUnique()->map(fn ($t) => ['tipus' => $t, ...$row['hosts'][$t->nom]])->values(),
            'globals' => $grid['globals'],
            'ansible' => $ansible,
            'actions' => ActionRun::query()
                ->where('alumne_id', $student->id)
                ->where('es_simulacio', false)
                ->whereHas('action', fn ($q) => $q->where('modul_id', $module->id))
                ->with('action')
                ->latest('id')
                ->limit(10)
                ->get(),
            'checks' => $student->checkStates()
                ->whereHas('check.task.block', fn ($q) => $q->where('modul_id', $module->id))
                ->with('check')
                ->get(),
        ];
    }
}
