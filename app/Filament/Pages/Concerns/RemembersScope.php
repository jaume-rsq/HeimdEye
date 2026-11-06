<?php

namespace App\Filament\Pages\Concerns;

/**
 * Recorda a la sessió el curs, el grup i el mòdul triats a dalt (i l'alumne, al seguiment), com
 * fan els filtres de les llistes. Són compartits entre el leaderboard, el seguiment i l'inventari:
 * el que es tria en una pantalla és el que surt a les altres. Un enllaç amb valors a la URL mana.
 */
trait RemembersScope
{
    private const SCOPE_SESSION_KEY = 'heimdeye.ambit';

    /** Propietats que es recorden: les tres comunes i les pròpies de la pàgina. */
    protected function rememberedScope(): array
    {
        return ['curs', 'grup', 'modul'];
    }

    /** Propietats que, si venen a la URL, fan que no es recuperi res (l'enllaç ja diu què cal veure). */
    protected function scopeLinkProperties(): array
    {
        return ['curs', 'grup', 'modul'];
    }

    protected function remembersScope(): bool
    {
        return true;
    }

    /** Al principi de mount(): si la URL no porta res, recupera el darrer triat. */
    protected function restoreScope(): void
    {
        if (! $this->remembersScope()) {
            return;
        }
        foreach ($this->scopeLinkProperties() as $property) {
            if ($this->{$property} !== null) {
                return;
            }
        }
        foreach ((array) session(self::SCOPE_SESSION_KEY, []) as $property => $value) {
            if (in_array($property, $this->rememberedScope(), true) && is_int($value)) {
                $this->{$property} = $value;
            }
        }
    }

    /** Livewire la crida al final de cada petició: desa el que hi ha triat ara. */
    public function dehydrateRemembersScope(): void
    {
        if (! $this->remembersScope()) {
            return;
        }
        $scope = (array) session(self::SCOPE_SESSION_KEY, []);
        foreach ($this->rememberedScope() as $property) {
            $scope[$property] = $this->{$property};
        }
        session([self::SCOPE_SESSION_KEY => $scope]);
    }
}
