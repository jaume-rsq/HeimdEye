<x-filament-panels::page>
    @if ($this->modules->count() > 1)
        <div>
            <select wire:model.live="modul" aria-label="Mòdul" style="padding: .35rem .6rem; border: 1px solid rgba(128,128,128,.35); border-radius: .5rem; background: transparent">
                @foreach ($this->modules as $m)
                    <option value="{{ $m->id }}">{{ trim($m->codi.' '.$m->nom) }}</option>
                @endforeach
            </select>
        </div>
    @endif

    <x-filament::section>
        @if ($this->board)
            @include('leaderboard.table', ['board' => $this->board, 'names' => true])
            <p style="font-size: .75rem; opacity: .65; margin-top: .75rem">
                Punts: els de cada check assolit, amb el bonus si es va assolir abans de la data límit (màxim possible ara: {{ rtrim(rtrim(number_format($this->board['punts_maxims'], 2, ',', ''), '0'), ',') }}).
                Compten els checks que ja han començat. La correcció del professor mana. A la versió pública només surt l'àlies o "Taula NN".
            </p>
        @else
            No hi ha cap mòdul al curs actual.
        @endif
    </x-filament::section>
</x-filament-panels::page>
