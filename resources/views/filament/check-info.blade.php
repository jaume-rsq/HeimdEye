@if ($check)
    <div style="display: grid; gap: 1rem; font-size: .875rem">
        {{-- Dates del check: quan està actiu i fins quan compta la puntualitat. --}}
        <div style="display: flex; flex-wrap: wrap; gap: .25rem 1.25rem; font-size: .8rem">
            @if ($check->es_actiu !== null)
                <span><span style="opacity: .65">Actiu:</span> {{ $check->es_actiu ? 'sempre (forçat)' : 'mai (desactivat)' }}</span>
            @else
                <span><span style="opacity: .65">Actiu des de:</span> {{ $check->actiu_des?->format('d/m/Y') ?? '—' }}</span>
                <span><span style="opacity: .65">Actiu fins:</span> {{ $check->actiu_fins?->format('d/m/Y') ?? '—' }}</span>
            @endif
            <span><span style="opacity: .65">Data límit:</span> {{ $check->data_limit?->format('d/m/Y H:i') ?? '—' }}</span>
        </div>
        @if (filled($check->info))
            <div class="fi-prose" style="line-height: 1.55">
                {!! \Illuminate\Support\Str::markdown($check->info, ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}
            </div>
        @else
            <div style="opacity: .65">Aquest check encara no té informació per a l'alumne (camp «Info» del check).</div>
        @endif

        {{-- Sense alumne (llista de checks) només es mostra el text. --}}
        @if ($preview !== null)
        <div>
            <div style="font-weight: 600; margin-bottom: .35rem">Codi del check amb les teves dades</div>
            @if ($preview && $preview['codi'] !== null)
                <pre style="white-space: pre-wrap; font-size: .75rem; max-height: 28rem; overflow: auto; background: rgba(128,128,128,.08); padding: .6rem; border-radius: .5rem; margin: 0">{{ $preview['codi'] }}</pre>
            @endif
            @foreach ($preview['avisos'] ?? [] as $avis)
                <div style="font-size: .75rem; color: rgb(217,119,6)">{{ $avis }}</div>
            @endforeach
            <div style="font-size: .75rem; opacity: .65; margin-top: .3rem">
                Versió v{{ $check->currentVersion?->versio ?? '?' }} · executor {{ $check->executor->value }}. Els secrets no es mostren mai.
            </div>
        </div>
        @endif
    </div>
@endif
