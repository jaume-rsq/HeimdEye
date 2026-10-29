{{-- Ajuda: índex de pàgines a l'esquerra i la pàgina triada a la dreta. Estils en línia: el tema de Filament no compila classes noves. --}}
<x-filament-panels::page>
    <style>
        .he-help { display: grid; grid-template-columns: minmax(12rem, 16rem) 1fr; gap: 1.5rem; align-items: start; }
        @media (max-width: 768px) { .he-help { grid-template-columns: 1fr; } }
        .he-help-nav { display: flex; flex-direction: column; gap: .15rem; position: sticky; top: 5rem; }
        .he-help-nav a { display: flex; justify-content: space-between; gap: .5rem; padding: .4rem .6rem; border-radius: .5rem; font-size: .875rem; }
        .he-help-nav a:hover { background: rgba(128,128,128,.1); }
        .he-help-nav a.active { background: rgba(245,158,11,.15); font-weight: 600; }
        .he-help-new { font-size: .7rem; padding: 0 .4rem; border-radius: .375rem; background: rgb(245,158,11); color: #fff; align-self: center; }
        .he-help-doc { background: rgba(128,128,128,.04); border: 1px solid rgba(128,128,128,.2); border-radius: .75rem; padding: 1.25rem 1.5rem; line-height: 1.6; min-width: 0; }
        .he-help-doc h1 { font-size: 1.4rem; font-weight: 700; margin: 0 0 .75rem; }
        .he-help-doc h2 { font-size: 1.15rem; font-weight: 700; margin: 1.5rem 0 .5rem; }
        .he-help-doc h3 { font-size: 1rem; font-weight: 600; margin: 1.2rem 0 .4rem; }
        .he-help-doc p, .he-help-doc ul, .he-help-doc ol, .he-help-doc table, .he-help-doc pre { margin: 0 0 .75rem; }
        .he-help-doc ul { list-style: disc; padding-left: 1.4rem; }
        .he-help-doc ol { list-style: decimal; padding-left: 1.4rem; }
        .he-help-doc li { margin: .15rem 0; }
        .he-help-doc a { color: rgb(217,119,6); text-decoration: underline; }
        .he-help-doc code { font-size: .85em; background: rgba(128,128,128,.12); padding: .05rem .3rem; border-radius: .25rem; }
        .he-help-doc pre { background: rgba(128,128,128,.1); padding: .75rem; border-radius: .5rem; overflow-x: auto; }
        .he-help-doc pre code { background: none; padding: 0; }
        .he-help-doc table { border-collapse: collapse; font-size: .875rem; display: block; overflow-x: auto; }
        .he-help-doc th, .he-help-doc td { border: 1px solid rgba(128,128,128,.25); padding: .35rem .6rem; text-align: left; vertical-align: top; }
        .he-help-doc blockquote { border-left: 3px solid rgb(245,158,11); padding-left: .75rem; opacity: .85; margin: 0 0 .75rem; }
        .he-help-meta { font-size: .75rem; opacity: .6; margin-bottom: 1rem; }
    </style>

    @php($current = $this->current())
    @if ($this->docs()->isEmpty())
        <div style="opacity: .65">Encara no hi ha cap pàgina d'ajuda.</div>
    @else
        <div class="he-help">
            <nav class="he-help-nav">
                @foreach ($this->docs() as $slug => $d)
                    <a href="{{ static::getUrl(['doc' => $slug]) }}" wire:navigate @class(['active' => $slug === $this->doc])>
                        <span>{{ $d['titol'] }}</span>
                        @if ($d['recent'])
                            <span class="he-help-new" title="Actualitzada el {{ $d['actualitzat']->format('d/m/Y') }}">nou</span>
                        @endif
                    </a>
                @endforeach
            </nav>
            <article class="he-help-doc">
                @if ($current['actualitzat'])
                    <div class="he-help-meta">Actualitzada el {{ $current['actualitzat']->format('d/m/Y') }}</div>
                @endif
                {!! $current['html'] !!}
            </article>
        </div>
    @endif
</x-filament-panels::page>
