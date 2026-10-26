{{-- Resultats de les proves de l'esborrany a l'editor (accions i checks). No es desen enlloc. Mentre n'hi ha a la cua, es recullen cada 2 s. --}}
@include('filament.partials.detail-styles')
@php($results = $getLivewire()->testResults)
@php($pending = collect($results)->contains(fn ($r) => isset($r['clau'])))
<div class="he-detail" @if ($pending) wire:poll.2s="refreshTestResults" @endif>
    @if ($results === [])
        <div style="opacity: .6;">Encara no has provat aquest esborrany. Fes servir el botó «Provar» de dalt.</div>
    @endif
    @foreach ($results as $result)
        <details @if ($loop->first) open @endif style="border: 1px solid rgba(127, 127, 127, .25); border-radius: .5rem; padding: .5rem .75rem;">
            <summary style="cursor: pointer; display: flex; gap: .75rem; align-items: center; flex-wrap: wrap;">
                <span style="opacity: .6;">{{ $result['hora'] }}</span>
                <span>{{ $result['titol'] }}</span>
                <span style="font-weight: 600;">{{ $result['alumne'] }}</span>
                <span style="color: #fff; background: {{ $result['color'] }}; border-radius: .375rem; padding: 0 .4rem; font-size: .75rem;">{{ $result['estat'] }}</span>
                @if ($result['resum'])
                    <span style="opacity: .7;">{{ $result['resum'] }}</span>
                @endif
            </summary>
            @if ($result['parametres'])
                <div style="margin-top: .5rem; opacity: .7;">Paràmetres: {{ $result['parametres'] }}</div>
            @endif
            @if (isset($result['clau']))
                <div style="margin-top: .5rem; opacity: .7;">A la cua o executant-se. Si no avança, comprova que el worker de la cua estigui en marxa.</div>
            @else
                @if ($result['comanda'] ?? null)
                    <details style="margin-top: .5rem;">
                        <summary style="cursor: pointer; opacity: .8;">Codi executat per a aquest alumne (amb variables i paràmetres substituïts)</summary>
                        <pre style="margin-top: .25rem;">{{ $result['comanda'] }}</pre>
                    </details>
                @endif
                <pre style="margin-top: .5rem;">{{ $result['sortida'] ?: '(sense sortida)' }}</pre>
            @endif
        </details>
    @endforeach
</div>
