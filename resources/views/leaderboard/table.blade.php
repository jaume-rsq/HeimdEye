{{-- Taula de classificació. $board = Leaderboard::build(); $names = mostrar el nom real (només al panell). --}}
@once
    <style>
        .he-lb { width: 100%; border-collapse: collapse; font-size: .9rem; }
        .he-lb th, .he-lb td { padding: .55rem .6rem; text-align: left; border-bottom: 1px solid rgba(128,128,128,.18); vertical-align: middle; }
        .he-lb th { font-size: .75rem; text-transform: uppercase; letter-spacing: .03em; opacity: .7; font-weight: 600; }
        .he-lb .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .he-lb .pos { width: 2.5rem; font-weight: 700; text-align: center; }
        .he-lb .medal-1 { color: rgb(202,138,4); } .he-lb .medal-2 { color: rgb(113,113,122); } .he-lb .medal-3 { color: rgb(180,83,9); }
        .he-lb .bar { position: relative; height: .5rem; min-width: 4rem; border-radius: 999px; background: rgba(128,128,128,.18); overflow: hidden; }
        .he-lb .bar > span { position: absolute; inset: 0 auto 0 0; background: rgb(245,158,11); border-radius: 999px; }
        .he-lb .bar.done > span { background: rgb(22,163,74); }
        .he-lb .sub { font-size: .75rem; opacity: .65; }
        .he-lb-wrap { overflow-x: auto; }
    </style>
@endonce
<div class="he-lb-wrap">
    <table class="he-lb">
        <thead>
            <tr>
                <th class="pos">#</th>
                <th>{{ $names ? 'Alumne' : 'Participant' }}</th>
                <th class="num">Punts</th>
                <th>Obligatoris</th>
                @foreach ($board['blocks'] as $b)
                    <th title="{{ $b['block']->titol }}">Bloc {{ $b['block']->numero }}</th>
                @endforeach
                <th>Darrer assoliment</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($board['rows'] as $row)
                <tr>
                    <td @class(['pos', 'medal-'.$row['posicio'] => $row['posicio'] <= 3 && $row['punts'] > 0])>{{ $row['posicio'] }}</td>
                    <td>
                        <strong>{{ $row['nom_public'] }}</strong>
                        @if ($names)
                            <div class="sub">{{ sprintf('%02d', $row['student']->num_taula) }} · {{ $row['student']->nom_complet }}</div>
                        @endif
                    </td>
                    <td class="num"><strong>{{ rtrim(rtrim(number_format($row['punts'], 2, ',', ''), '0'), ',') }}</strong></td>
                    <td style="min-width: 8rem">
                        <div @class(['bar', 'done' => $row['percentatge'] === 100])><span style="width: {{ $row['percentatge'] }}%"></span></div>
                        <div class="sub">{{ $row['obligatoris'] }}/{{ $row['obligatoris_total'] }} · {{ $row['percentatge'] }}%</div>
                    </td>
                    @foreach ($board['blocks'] as $b)
                        @php $cell = $row['blocs'][$b['block']->id]; @endphp
                        <td title="{{ $cell['assolits'] }}/{{ $cell['total'] }} checks">
                            <div @class(['bar', 'done' => $cell['percentatge'] === 100])><span style="width: {{ $cell['percentatge'] }}%"></span></div>
                            <div class="sub">{{ $cell['assolits'] }}/{{ $cell['total'] }}</div>
                        </td>
                    @endforeach
                    <td class="sub">{{ $row['darrer_assoliment']?->format('d/m/Y H:i') ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="{{ 5 + count($board['blocks']) }}" class="sub">Encara no hi ha alumnes en aquest mòdul.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
