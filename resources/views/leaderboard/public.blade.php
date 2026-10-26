<!doctype html>
<html lang="ca">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="300">
    <title>Leaderboard · HeimdEye</title>
    <style>
        :root { --bg: #f9fafb; --card: #fff; --fg: #111827; --accent: rgb(217,119,6); color-scheme: light dark; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0b0f17; --card: #111827; --fg: #f3f4f6; --accent: rgb(251,191,36); } }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--fg); font-family: ui-sans-serif, system-ui, sans-serif; }
        main { max-width: 72rem; margin: 0 auto; padding: 1.5rem 1rem 3rem; }
        header { display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; justify-content: space-between; margin-bottom: 1rem; }
        h1 { margin: 0; font-size: 1.5rem; } h1 span { color: var(--accent); }
        .card { background: var(--card); border: 1px solid rgba(128,128,128,.2); border-radius: .75rem; padding: .5rem 1rem; }
        .modules { display: flex; gap: .5rem; flex-wrap: wrap; }
        .modules a { padding: .3rem .7rem; border-radius: 999px; border: 1px solid rgba(128,128,128,.3); color: inherit; text-decoration: none; font-size: .85rem; }
        .modules a.on { background: var(--accent); border-color: var(--accent); color: #111; font-weight: 600; }
        footer { margin-top: .75rem; font-size: .75rem; opacity: .6; }
    </style>
</head>
<body>
<main>
    <header>
        <h1><span>HeimdEye</span> · Leaderboard{{ $module ? ' · '.$module->nom : '' }}</h1>
        @if ($modules->count() > 1)
            <nav class="modules">
                @foreach ($modules as $m)
                    <a href="{{ url('/leaderboard?modul='.$m->id) }}" @class(['on' => $module?->id === $m->id])>{{ $m->nom }}</a>
                @endforeach
            </nav>
        @endif
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
