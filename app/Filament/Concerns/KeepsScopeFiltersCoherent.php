<?php

namespace App\Filament\Concerns;

use Filament\Tables\Filters\SelectFilter;

/**
 * Filtres d'àmbit (curs, grup, mòdul, bloc, tasca, alumne) coherents a les llistes:
 *
 * - Un filtre fill amb un valor que ja no és a les seves opcions (perquè ha canviat el pare, en
 *   qualsevol ordre: al formulari, traient-ne l'indicador o en tornar a la pàgina) es buida.
 * - El curs, el grup i el mòdul es comparteixen amb el leaderboard, el seguiment, l'inventari i
 *   les altres llistes (la mateixa clau de sessió que RemembersScope): el que es tria en una
 *   pantalla és el que surt a la següent.
 */
trait KeepsScopeFiltersCoherent
{
    /** Ja s'ha agafat l'àmbit compartit en obrir la pàgina. */
    public bool $scopeFiltersSynced = false;

    /** Filtres de la llista que es comparteixen, i amb quina clau de l'àmbit. */
    protected function sharedScopeFilters(): array
    {
        return ['curs' => 'curs', 'curs_id' => 'curs', 'grup' => 'grup', 'grup_id' => 'grup', 'modul' => 'modul', 'modul_id' => 'modul'];
    }

    /** Filtres encadenats i el seu nivell: canviar-ne un buida els de nivell més alt (els fills). */
    protected function scopeFilterLevels(): array
    {
        return ['curs' => 0, 'curs_id' => 0, 'grup' => 1, 'grup_id' => 1, 'modul' => 2, 'modul_id' => 2, 'bloc' => 3, 'tasca' => 4, 'alumne_id' => 5];
    }

    protected function chainedScopeFilters(): array
    {
        return array_keys($this->scopeFilterLevels());
    }

    /** En obrir la pàgina (no a cada petició): pren l'àmbit compartit i deixa els fills coherents. */
    public function bootedKeepsScopeFiltersCoherent(): void
    {
        if ($this->scopeFiltersSynced) {
            return;
        }
        $this->scopeFiltersSynced = true;

        $scope = (array) session('heimdeye.ambit', []);
        foreach ($this->sharedScopeFilters() as $name => $key) {
            $filter = $this->getTable()->getFilter($name);
            if ($filter === null || ! $filter->isVisible() || ! is_int($scope[$key] ?? null)) {
                continue;
            }
            $this->setScopeFilterValue($name, $scope[$key]);
        }
        $this->sanitizeScopeFilters();

        if ($this->getTable()->persistsFiltersInSession()) {
            session()->put($this->getTableFiltersSessionKey(), $this->tableFilters);
        }
    }

    /** En canviar un desplegable del formulari de filtres (encara sense aplicar): es buiden els fills. */
    public function updatedTableDeferredFilters(mixed $value = null, ?string $key = null): void
    {
        $name = $key === null ? null : explode('.', $key)[0];
        if ($name !== null && isset($this->scopeFilterLevels()[$name])) {
            $this->clearScopeChildren($name, ['tableDeferredFilters']);
        }
        $this->sanitizeScopeFilters(deferredOnly: true);
    }

    /** Treure l'indicador d'un filtre (la creu) també treu els seus fills. */
    public function removeTableFilter(string $filterName, ?string $field = null, bool $isRemovingAllFilters = false): void
    {
        if (isset($this->scopeFilterLevels()[$filterName])) {
            $this->clearScopeChildren($filterName, ['tableDeferredFilters', 'tableFilters']);
        }

        parent::removeTableFilter($filterName, $field, $isRemovingAllFilters);
    }

    /** @param  list<string>  $properties */
    private function clearScopeChildren(string $name, array $properties): void
    {
        $level = $this->scopeFilterLevels()[$name];
        foreach ($this->scopeFilterLevels() as $child => $childLevel) {
            foreach ($properties as $property) {
                if ($childLevel > $level && isset($this->{$property}[$child]) && array_key_exists('value', $this->{$property}[$child])) {
                    $this->{$property}[$child]['value'] = null;
                }
            }
        }
    }

    /** Tota aplicació de filtres (aplicar, treure un indicador, restablir) passa per aquí. */
    protected function handleTableFilterUpdates(): void
    {
        $this->sanitizeScopeFilters();
        $this->shareScopeFilters();

        parent::handleTableFilterUpdates();
    }

    /**
     * Buida els filtres encadenats que ja no són a les seves opcions. Les opcions de cada fill
     * depenen del valor del pare, així que es repeteix fins que no canvia res.
     */
    protected function sanitizeScopeFilters(bool $deferredOnly = false): void
    {
        $table = $this->getTable();
        $property = $table->hasDeferredFilters() ? 'tableDeferredFilters' : 'tableFilters';

        for ($round = 0; $round < 5; $round++) {
            $changed = false;
            foreach ($this->chainedScopeFilters() as $name) {
                $filter = $table->getFilter($name);
                $value = $this->{$property}[$name]['value'] ?? null;
                if (! $filter instanceof SelectFilter || $filter->isMultiple() || blank($value)) {
                    continue;
                }
                if (! $this->optionExists($filter->getOptions(), $value)) {
                    $this->{$property}[$name]['value'] = null;
                    $changed = true;
                }
            }
            if (! $changed) {
                break;
            }
        }

        if (! $deferredOnly && $property === 'tableDeferredFilters') {
            foreach ($this->chainedScopeFilters() as $name) {
                if (isset($this->tableFilters[$name]) && array_key_exists('value', $this->tableDeferredFilters[$name] ?? [])) {
                    $this->tableFilters[$name]['value'] = $this->tableDeferredFilters[$name]['value'];
                }
            }
        }
    }

    /** Desa el curs, el grup i el mòdul aplicats a l'àmbit compartit (només els filtres visibles). */
    protected function shareScopeFilters(): void
    {
        $scope = (array) session('heimdeye.ambit', []);
        foreach ($this->sharedScopeFilters() as $name => $key) {
            $filter = $this->getTable()->getFilter($name);
            if ($filter === null || ! $filter->isVisible() || ! array_key_exists($name, $this->tableFilters ?? [])) {
                continue;
            }
            $value = $this->tableFilters[$name]['value'] ?? null;
            $scope[$key] = filled($value) ? (int) $value : null;
        }
        session(['heimdeye.ambit' => $scope]);
    }

    private function setScopeFilterValue(string $name, int $value): void
    {
        $this->tableFilters[$name]['value'] = (string) $value;
        if ($this->getTable()->hasDeferredFilters()) {
            $this->tableDeferredFilters[$name]['value'] = (string) $value;
        }
    }

    /** Les opcions poden venir agrupades (optgroups). */
    private function optionExists(array $options, mixed $value): bool
    {
        foreach ($options as $key => $label) {
            if (is_array($label) ? $this->optionExists($label, $value) : (string) $key === (string) $value) {
                return true;
            }
        }

        return false;
    }
}
