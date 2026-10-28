<?php

namespace App\Filament\Pages\Concerns;

use App\Enums\Level;
use App\Filament\Pages\InventoryOverview;
use App\Models\AcademicYear;
use App\Models\Module;
use App\Support\InventoryGrid;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * Nivells que es veuen des del mòdul triat a l'Inventari (global, el seu curs i el mòdul), o
 * només els comuns (global i curs actual) si no n'hi ha cap de triat. Comú a variables i secrets.
 */
trait ManagesLevels
{
    #[Computed]
    public function module(): ?Module
    {
        return $this->modul ? Module::find($this->modul) : null;
    }

    #[Computed]
    public function course(): ?AcademicYear
    {
        return $this->module?->academicYear ?? AcademicYear::where('es_actual', true)->first();
    }

    /** @return list<Level> */
    public function levels(): array
    {
        return array_values(array_filter([
            Level::Global,
            $this->course ? Level::Course : null,
            $this->module ? Level::Module : null,
        ]));
    }

    public function levelLabel(Level $level): string
    {
        return match ($level) {
            Level::Global => 'Global',
            Level::Course => 'Curs '.$this->course?->nom,
            Level::Module => 'Mòdul '.$this->module?->codi,
        };
    }

    /** Camps que situen un registre en un nivell. */
    protected function levelAttributes(Level $level): array
    {
        return [
            'nivell' => $level,
            'curs_id' => $level === Level::Course ? $this->course?->id : null,
            'modul_id' => $level === Level::Module ? $this->module?->id : null,
        ];
    }

    /** Limita una consulta (variables o secrets) als nivells visibles. */
    protected function atLevels(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            foreach ($this->levels() as $level) {
                $q->orWhere(fn (Builder $q) => match ($level) {
                    Level::Global => $q->where('nivell', Level::Global),
                    Level::Course => $q->where('nivell', Level::Course)->where('curs_id', $this->course?->id),
                    Level::Module => $q->where('nivell', Level::Module)->where('modul_id', $this->module?->id),
                });
            }
        });
    }

    /** Registres per nom i nivell: [nom => [nivell => registre]], per ordre de nom. */
    protected function byLevel(Collection $records): array
    {
        $rows = [];
        foreach ($records as $record) {
            $rows[$record->clau][$record->nivell->value] = $record;
        }
        ksort($rows);

        return $rows;
    }

    /** El nivell que mana entre els que tenen valor: el més concret. */
    public function winner(array $cells): ?string
    {
        foreach ([Level::Module, Level::Course, Level::Global] as $level) {
            if (isset($cells[$level->value])) {
                return $level->value;
            }
        }

        return null;
    }

    /** Mòduls on pot canviar alguna cosa en tocar un nivell. */
    protected function modulesAt(Level $level): Collection
    {
        return match ($level) {
            Level::Global => Module::query()->whereIn('curs_id', AcademicYear::where('es_actual', true)->pluck('id'))->get(),
            Level::Course => Module::query()->where('curs_id', $this->course?->id)->get(),
            Level::Module => collect([$this->module])->filter(),
        };
    }

    public function reach(Level $level): string
    {
        return match ($level) {
            Level::Global => 'Afecta tots els mòduls ('.$this->modulesAt($level)->count().' aquest curs) que no el tapen amb un del curs o del mòdul.',
            Level::Course => 'Afecta els '.$this->modulesAt($level)->count().' mòduls del curs '.$this->course?->nom.' que no el tapen.',
            Level::Module => 'Només aquest mòdul.',
        };
    }

    /** Opcions de nivell per crear-ne un de nou (per defecte, el més concret). */
    protected function levelOptions(): array
    {
        return collect($this->levels())->mapWithKeys(fn (Level $level) => [$level->value => $this->levelLabel($level)])->all();
    }

    /** Després de desar, avisa si alguna plantilla deixa de calcular-se per a algun alumne. */
    protected function warnBrokenTemplates(Level $level): void
    {
        $grid = app(InventoryGrid::class);
        $problems = [];
        foreach ($this->modulesAt($level) as $module) {
            $built = $grid->build($module);
            $tables = collect($built['rows'])->filter(fn (array $row) => $row['problemes'] > 0)
                ->map(fn (array $row) => sprintf('%02d', $row['student']->num_taula));
            $hosts = collect($built['globals'])->filter(fn (array $host) => $host['problemes'] !== [])->map(fn (array $h) => $h['tipus']->nom);
            if ($tables->isNotEmpty() || $hosts->isNotEmpty()) {
                $problems[] = trim($module->codi.': '.$tables->merge($hosts)->implode(', '));
            }
        }
        if ($problems === []) {
            return;
        }

        Notification::make()
            ->warning()
            ->persistent()
            ->title('Hi ha plantilles que no es poden calcular')
            ->body('Alumnes (taula) o equips afectats. '.implode('. ', $problems).'.')
            ->actions([
                Action::make('veure')
                    ->label('Veure-ho')
                    ->url(InventoryOverview::getUrl(['modul' => $this->modulesAt($level)->first()?->id, 'pestanya' => 'alumnes', 'problemes' => 1])),
            ])
            ->send();
    }
}
