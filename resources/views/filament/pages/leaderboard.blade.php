<x-filament-panels::page>
    <x-filament::section>
        @if ($this->board)
            @include('leaderboard.table', ['board' => $this->board, 'names' => true, 'link' => fn ($student) => \App\Filament\Pages\StudentTracking::getUrl(['alumne' => $student->id, 'modul' => $modul, 'grup' => $grup])])
            <p style="font-size: .75rem; opacity: .65; margin-top: .75rem">
                Punts: els de cada check assolit, amb el bonus si es va assolir abans de la data límit (màxim possible ara: {{ rtrim(rtrim(number_format($this->board['punts_maxims'], 2, ',', ''), '0'), ',') }}).
                Compten els checks que ja han començat. La correcció del professor mana. A la versió pública només surt l'àlies o "Taula NN".
            </p>
        @else
            No hi ha cap mòdul al curs actual amb alumnes d'aquest grup.
        @endif
    </x-filament::section>
</x-filament-panels::page>
