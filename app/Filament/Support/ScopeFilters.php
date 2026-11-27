<?php

namespace App\Filament\Support;

use App\Models\AcademicYear;
use App\Models\Block;
use App\Models\Group;
use App\Models\Module;
use App\Models\Student;
use App\Models\Task;
use Filament\Forms\Components\Select;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filtres encadenats de les llistes: curs → mòdul → bloc → tasca (i curs → grup). Cada desplegable només
 * ofereix el que hi ha dins del triat al de sobre, i en canviar-lo es buiden els de sota.
 * Les claus dels filtres (curs, modul, bloc, grup) són les de sempre: els enllaços i els filtres
 * desats a la sessió continuen funcionant.
 */
class ScopeFilters
{
    /**
     * @param  \Closure(Builder, int): mixed  $apply  com filtra la consulta pel curs triat
     * @param  list<string>  $children  filtres que depenen del curs (es buiden en canviar-lo)
     */
    public static function course(string $name, \Closure $apply, array $children = [], ?\Closure $default = null): SelectFilter
    {
        return SelectFilter::make($name)
            ->label('Curs')
            ->options(fn () => AcademicYear::orderByDesc('data_inici')->pluck('nom', 'id'))
            ->default($default ?? fn () => AcademicYear::where('es_actual', true)->value('id'))
            ->modifyFormFieldUsing(fn (Select $field) => self::resets($field, $children))
            ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $q, $id) => $apply($q, (int) $id)));
    }

    /**
     * @param  \Closure(Builder, int): mixed  $apply
     * @param  list<string>  $children
     * @param  string|null  $group  filtre de grup del qual depèn (si n'hi ha)
     */
    public static function module(string $name, string $course, \Closure $apply, array $children = [], ?string $group = null): SelectFilter
    {
        return SelectFilter::make($name)
            ->label('Mòdul')
            ->options(function (HasTable $livewire) use ($course, $group) {
                $year = self::value($livewire, $course);
                $groupId = $group ? self::value($livewire, $group) : null;

                // Amb un grup triat, només els mòduls on el grup té alumnes (com al seguiment).
                return Module::with('academicYear')
                    ->when($year, fn (Builder $q) => $q->where('curs_id', $year))
                    ->when($groupId, fn (Builder $q) => $q->withGroup($groupId))
                    ->orderBy('codi')->orderBy('nom')->get()
                    ->mapWithKeys(fn (Module $m) => [$m->id => $m->label().($year ? '' : " ({$m->academicYear?->nom})")]);
            })
            ->modifyFormFieldUsing(fn (Select $field) => self::resets($field, $children))
            ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $q, $id) => $apply($q, (int) $id)));
    }

    /**
     * @param  \Closure(Builder, int): mixed  $apply
     * @param  list<string>  $children
     */
    public static function block(string $name, string $course, string $module, \Closure $apply, array $children = []): SelectFilter
    {
        return SelectFilter::make($name)
            ->label('Bloc')
            ->options(function (HasTable $livewire) use ($course, $module) {
                $moduleId = self::value($livewire, $module);
                $year = self::value($livewire, $course);

                return Block::with('module')
                    ->when($moduleId, fn (Builder $q) => $q->where('modul_id', $moduleId))
                    ->when(! $moduleId && $year, fn (Builder $q) => $q->whereHas('module', fn (Builder $m) => $m->where('curs_id', $year)))
                    ->orderBy('modul_id')->orderBy('posicio')->orderBy('numero')->get()
                    ->mapWithKeys(fn (Block $b) => [$b->id => ($moduleId ? '' : "{$b->module->codi} · ")."Bloc {$b->numero}: {$b->titol}"]);
            })
            ->modifyFormFieldUsing(fn (Select $field) => self::resets($field, $children))
            ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $q, $id) => $apply($q, (int) $id)));
    }

    /**
     * Tasques del bloc triat. Sense bloc no n'ofereix cap: n'hi hauria massa per triar.
     *
     * @param  \Closure(Builder, int): mixed  $apply
     */
    public static function task(string $name, string $block, \Closure $apply): SelectFilter
    {
        return SelectFilter::make($name)
            ->label('Tasca')
            ->options(fn (HasTable $livewire) => ($blockId = self::value($livewire, $block))
                ? Task::where('bloc_id', $blockId)->orderBy('posicio')->pluck('titol', 'id')
                : [])
            ->placeholder('Totes')
            ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $q, $id) => $apply($q, (int) $id)));
    }

    /**
     * @param  \Closure(Builder, int): mixed  $apply
     * @param  list<string>  $children
     */
    public static function group(string $name, string $course, \Closure $apply, array $children = []): SelectFilter
    {
        return SelectFilter::make($name)
            ->label('Grup')
            ->options(function (HasTable $livewire) use ($course) {
                $year = self::value($livewire, $course);

                return Group::with('academicYear')
                    ->when($year, fn (Builder $q) => $q->where('curs_id', $year))
                    ->orderBy('codi')->get()
                    ->mapWithKeys(fn (Group $g) => [$g->id => $g->label().($year ? '' : " ({$g->academicYear->nom})")]);
            })
            ->modifyFormFieldUsing(fn (Select $field) => self::resets($field, $children))
            ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $q, $id) => $apply($q, (int) $id)));
    }

    /**
     * Alumnes actius del curs, el grup i el mòdul triats (o del mòdul fix, a l'historial d'un check
     * o d'una acció). Filtra per la columna alumne_id.
     */
    public static function student(string $name, string $course, string $group, string $module, ?int $fixedModuleId = null): SelectFilter
    {
        return SelectFilter::make($name)
            ->label('Alumne')
            ->options(function (HasTable $livewire) use ($course, $group, $module, $fixedModuleId) {
                $year = self::value($livewire, $course);
                $groupId = self::value($livewire, $group);
                $moduleId = $fixedModuleId ?? self::value($livewire, $module);

                return Student::where('es_actiu', true)
                    ->when($groupId, fn (Builder $q) => $q->where('grup_id', $groupId))
                    ->when(! $groupId && ! $moduleId && $year, fn (Builder $q) => $q->where('curs_id', $year))
                    ->when($moduleId, fn (Builder $q) => $q->whereHas('modules', fn (Builder $m) => $m->whereKey($moduleId)))
                    ->orderBy('num_taula')->get()
                    ->mapWithKeys(fn (Student $s) => [$s->id => sprintf('%02d · %s', $s->num_taula, $s->nom_complet)]);
            })
            ->searchable()
            ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $q, $id) => $q->where($name, (int) $id)));
    }

    /** El valor que es veu al formulari de filtres (amb filtres diferits, encara sense aplicar). */
    public static function value(HasTable $livewire, string $filter): ?int
    {
        $value = data_get(self::state($livewire), "{$filter}.value");

        return filled($value) ? (int) $value : null;
    }

    private static function stateProperty(HasTable $livewire): string
    {
        return $livewire->getTable()->hasDeferredFilters() ? 'tableDeferredFilters' : 'tableFilters';
    }

    private static function state(HasTable $livewire): array
    {
        return $livewire->{self::stateProperty($livewire)} ?? [];
    }

    /** En canviar aquest desplegable, buida els que en depenen (són filtres germans). */
    private static function resets(Select $field, array $children): Select
    {
        return $children === [] ? $field : $field->live()->afterStateUpdated(function (HasTable $livewire) use ($children) {
            foreach ($children as $child) {
                $property = self::stateProperty($livewire);
                if (isset($livewire->{$property}[$child])) {
                    $livewire->{$property}[$child]['value'] = null;
                }
            }
        });
    }
}
