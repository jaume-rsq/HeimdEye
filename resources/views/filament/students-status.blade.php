{{--
    Estat per alumne d'una acció o d'un check: la darrera execució real de cada alumne actiu.
    Verd: bé amb la versió vigent. Verd clar amb el número de versió: bé amb una d'anterior.
    Taronja o vermell: ha fallat (amb la versió, si no és la vigent). Gris: mai executat.
    $legend: text de sota la graella.
--}}
@php
    $students = $students ?? [];
    $style = function (array $s): array {
        if ($s['estat'] === null) {
            return ['#e5e7eb', '#374151'];
        }
        if (! $s['estat']->isFailure()) {
            return $s['vigent'] ? ['#16a34a', '#fff'] : ['#bbf7d0', '#14532d'];
        }

        return $s['estat']->getColor() === 'danger' ? ['#dc2626', '#fff'] : ['#d97706', '#fff'];
    };
@endphp
<div style="display: flex; flex-direction: column; gap: .5rem;">
    <div style="font-weight: 600;">Estat per alumne</div>
    <div style="display: flex; flex-wrap: wrap; gap: .375rem;">
        @foreach ($students as $s)
            @php([$bg, $fg] = $style($s))
            @if (isset($s['url']))<a href="{{ $s['url'] }}" style="text-decoration: none">@endif
            <div title="{{ $s['taula'] }} · {{ $s['nom'] }} · {{ $s['estat']?->value ?? 'mai executat' }}{{ $s['versio'] ? ' · v'.$s['versio'] : '' }}{{ $s['data'] ? ' · '.$s['data'] : '' }}"
                 style="width: 3rem; height: 3rem; border-radius: .375rem; background: {{ $bg }}; color: {{ $fg }}; display: flex; flex-direction: column; align-items: center; justify-content: center; line-height: 1.1;">
                <span style="font-weight: 600;">{{ $s['taula'] }}</span>
                @if ($s['estat'] !== null && ! $s['vigent'])
                    <span style="font-size: .7rem;">v{{ $s['versio'] ?? '?' }}</span>
                @endif
            </div>
            @if (isset($s['url']))</a>@endif
        @endforeach
    </div>
    <div style="font-size: .75rem; opacity: .7;">
        {{ $legend ?? 'Verd: bé amb la versió vigent · verd clar amb número: bé amb una versió anterior · taronja o vermell: ha fallat · gris: mai executada.' }}
    </div>
</div>
