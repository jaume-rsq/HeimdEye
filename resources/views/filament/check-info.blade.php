@if ($check)
    <div style="display: grid; gap: 1rem; font-size: .875rem">
        @if (filled($check->info))
            <div class="fi-prose" style="line-height: 1.55">
                {!! \Illuminate\Support\Str::markdown($check->info, ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}
            </div>
        @else
            <div style="opacity: .65">Aquest check encara no té informació per a l'alumne (camp «Info» del check).</div>
        @endif

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
    </div>
@endif
