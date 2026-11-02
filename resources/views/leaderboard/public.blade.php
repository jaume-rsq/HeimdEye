<!doctype html>
<html lang="ca">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="300">
    <title>Leaderboard · HeimdEye</title>
    @include('partials.favicons')
    <style>
        :root { --bg: #f9fafb; --card: #fff; --fg: #111827; --accent: rgb(217,119,6); color-scheme: light dark; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0b0f17; --card: #111827; --fg: #f3f4f6; --accent: rgb(251,191,36); } }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--fg); font-family: ui-sans-serif, system-ui, sans-serif; }
        main { max-width: 72rem; margin: 0 auto; padding: 1.5rem 1rem 3rem; }
        header { display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; justify-content: space-between; margin-bottom: 1rem; }
        h1 { margin: 0; font-size: 1.5rem; } h1 span { color: var(--accent); }
        .card { background: var(--card); border: 1px solid rgba(128,128,128,.2); border-radius: .75rem; padding: .5rem 1rem; }
        .pick { display: flex; gap: .5rem; flex-wrap: wrap; }
        .pick select { padding: .35rem .6rem; border: 1px solid rgba(128,128,128,.35); border-radius: .5rem; background: var(--card); color: inherit; font-size: .9rem; max-width: 100%; }
        footer { margin-top: .75rem; font-size: .75rem; opacity: .6; }
    </style>
</head>
<body>
<main>
    <header>
        <h1><span>HeimdEye</span> · Leaderboard{{ $module ? ' · '.$module->label() : '' }}</h1>
        {{-- Grup-classe i, d'aquest, un mòdul amb algun alumne del grup. Sense JavaScript, un botó. --}}
        <form class="pick" method="get" action="{{ url('/leaderboard') }}">
            @if ($groups->isNotEmpty())
                <select name="grup" aria-label="Grup" onchange="this.form.modul.value = ''; this.form.submit()">
                    <option value="">Tots els grups</option>
                    @foreach ($groups as $g)
                        <option value="{{ $g->id }}" @selected($group?->id === $g->id)>{{ $g->label() }}</option>
                    @endforeach
                </select>
            @endif
            <select name="modul" aria-label="Mòdul" onchange="this.form.submit()">
                @foreach ($modules as $m)
                    <option value="{{ $m->id }}" @selected($module?->id === $m->id)>{{ $m->label() }}</option>
                @endforeach
            </select>
            <noscript><button type="submit">Mostra</button></noscript>
        </form>
    </header>
    <div class="card">
        @if ($board)
            @include('leaderboard.table', ['board' => $board, 'names' => false])
        @else
            <p>Encara no hi ha cap mòdul aquest curs.</p>
        @endif
    </div>
    <footer>Actualitzat {{ now()->format('d/m/Y H:i') }}. Es refresca cada 5 minuts.</footer>
</main>
</body>
</html>
