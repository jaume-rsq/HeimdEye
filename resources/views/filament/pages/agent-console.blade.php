{{-- Consola interactiva d'una instància. Estils en línia: el tema de Filament no compila classes noves. --}}
<x-filament-panels::page>
    <style>
        .he-con-bar { display: flex; gap: .5rem; align-items: end; flex-wrap: wrap; margin-bottom: 1rem; }
        .he-con-bar label { display: flex; flex-direction: column; gap: .25rem; font-size: .8rem; font-weight: 600; }
        .he-con-sel { padding: .45rem .6rem; border-radius: .5rem; border: 1px solid rgba(128,128,128,.4); background: rgba(128,128,128,.05); min-width: 18rem; }
        .he-con-term { background: #0b0f1a; color: #d6deeb; border-radius: .6rem; padding: .9rem 1rem; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .82rem; line-height: 1.5; height: 26rem; overflow-y: auto; }
        .he-con-line { white-space: pre-wrap; word-break: break-word; }
        .he-con-in { color: #7fdbca; }
        .he-con-out { color: #d6deeb; }
        .he-con-empty { opacity: .5; }
        .he-con-form { display: flex; gap: .5rem; margin-top: .75rem; }
        .he-con-cmd { flex: 1; padding: .5rem .7rem; border-radius: .5rem; border: 1px solid rgba(128,128,128,.4); background: rgba(128,128,128,.05); font-family: ui-monospace, monospace; }
        .he-con-state { font-size: .8rem; opacity: .7; margin-bottom: .5rem; }
        .he-con-dot { display: inline-block; width: .55rem; height: .55rem; border-radius: 50%; margin-right: .35rem; vertical-align: middle; }
    </style>

    <div class="he-con-bar">
        <label>
            Instància activa
            <select class="he-con-sel" wire:model="instancia">
                <option value="">— Tria una instància —</option>
                @foreach ($this->instances as $inst)
                    <option value="{{ $inst->id }}">{{ $inst->hostname }} — {{ $inst->student?->publicName() ?? $inst->student?->nom_complet ?? ('alumne '.$inst->alumne_id) }}</option>
                @endforeach
            </select>
        </label>
        {{-- «Obrir consola» només si no hi ha cap sessió oberta; «Tancar» només si n'hi ha una. --}}
        @if (! ($sessioId && ! $tancada))
            <x-filament::button wire:click="obrir" icon="heroicon-o-play" :disabled="! $instancia">
                Obrir consola
            </x-filament::button>
        @else
            <x-filament::button color="danger" wire:click="tancar" icon="heroicon-o-x-mark">
                Tancar
            </x-filament::button>
        @endif
    </div>

    @if ($this->instances->isEmpty())
        <div class="he-con-empty" style="margin-bottom:1rem">No hi ha cap instància activa ara mateix. Quan un alumne engegui l'agent, apareixerà aquí.</div>
    @endif

    @if ($sessioId)
        <div
            @if (! $tancada) wire:poll.1s="refrescar" @endif
        >
            <div class="he-con-state">
                @if ($tancada)
                    <span class="he-con-dot" style="background:#9ca3af"></span> Sessió tancada
                @else
                    <span class="he-con-dot" style="background:#34d399"></span> Sessió oberta — s'està esperant/executant a la instància
                @endif
            </div>

            {{-- Línies en blocs (no «pre» al contenidor) perquè la sagnia del Blade no desplaci el text. --}}
            <div class="he-con-term" id="he-con-term" x-data x-init="$nextTick(() => $el.scrollTop = $el.scrollHeight)" x-effect="$nextTick(() => $el.scrollTop = $el.scrollHeight)">
                @forelse ($linies as $linia)
                    <div class="he-con-line {{ $linia['tipus'] === 'entrada' ? 'he-con-in' : 'he-con-out' }}">{{ $linia['tipus'] === 'entrada' ? '$ ' : '' }}{{ rtrim($linia['text'], "\n") }}</div>
                @empty
                    <div class="he-con-empty">Esperant que l'agent obri el bash a la instància…</div>
                @endforelse
            </div>

            @if (! $tancada)
                <form class="he-con-form" wire:submit.prevent="enviar">
                    <input class="he-con-cmd" type="text" wire:model="ordre" placeholder="Escriu una comanda i prem Retorn (p. ex. ls -la)" autocomplete="off" autofocus />
                    <x-filament::button type="submit" icon="heroicon-o-paper-airplane">Envia</x-filament::button>
                </form>
            @endif
        </div>
    @endif
</x-filament-panels::page>
