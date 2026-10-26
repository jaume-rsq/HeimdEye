{{-- Resultats de les proves de l'esborrany a l'editor d'accions. No es desen enlloc. --}}
@include('filament.partials.detail-styles')
@php($results = $getLivewire()->testResults)
<div class="he-detail">
    @if ($results === [])
        <div style="opacity: .6;">Encara no has provat aquest esborrany. Fes servir el botó «Provar» de dalt: simular, executar o verificar amb un check.</div>
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
            <pre style="margin-top: .5rem;">{{ $result['sortida'] ?: '(sense sortida)' }}</pre>
        </details>
    @endforeach
</div>
