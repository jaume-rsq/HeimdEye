{{--
    Estat per alumne d'una acció o d'un check: la darrera execució real de cada alumne actiu.
    Verd: bé amb la versió vigent. Verd clar amb el número de versió: bé amb una d'anterior.
    Taronja o vermell: ha fallat (amb la versió, si no és la vigent). Gris: mai executat.
    Amb ✎: corregit pel professor (verd assolit, vermell no assolit), sigui quina sigui l'execució.
    $legend: text de sota la graella.
--}}
@php
    $students = $students ?? [];
    $style = function (array $s): array {
        if (($s['correccio'] ?? null) !== null) {
            return $s['correccio'] ? ['#16a34a', '#fff'] : ['#dc2626', '#fff'];
        }
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
            @php($corrected = ($s['correccio'] ?? null) !== null)
            <div title="{{ $s['taula'] }} · {{ $s['nom'] }}{{ $corrected ? ' · corregit pel professor: '.($s['correccio'] ? 'assolit' : 'no assolit') : '' }} · darrera execució: {{ $s['estat']?->value ?? 'mai executat' }}{{ $s['versio'] ? ' · v'.$s['versio'] : '' }}{{ $s['data'] ? ' · '.$s['data'] : '' }}"
                 style="width: 3rem; height: 3rem; border-radius: .375rem; background: {{ $bg }}; color: {{ $fg }}; display: flex; flex-direction: column; align-items: center; justify-content: center; line-height: 1.1;">
                <span style="font-weight: 600;">{{ $s['taula'] }}</span>
                @if ($corrected)
                    <span style="font-size: .7rem;">✎</span>
                @elseif ($s['estat'] !== null && ! $s['vigent'])
                    <span style="font-size: .7rem;">v{{ $s['versio'] ?? '?' }}</span>
                @endif
            </div>
            @if (isset($s['url']))</a>@endif
        @endforeach
    </div>
    @if (filled($corrections ?? null))
        <div style="font-size: .85rem; display: flex; flex-direction: column; gap: .2rem;">
            <div style="font-weight: 600;">Correccions del professor</div>
            @foreach ($corrections as $c)
                <div>
                    ✎ {{ sprintf('%02d', $c->student->num_taula) }} · {{ $c->student->nom_complet }}:
                    <strong>{{ match ($c->estat_professor) { true => 'assolit', false => 'no assolit', null => 'automàtic' } }}</strong>
                    <span style="opacity: .7;">· {{ $c->teacherUpdatedBy?->nom ?: 'professor' }} el {{ $c->data_correccio->format('d/m/Y H:i') }}</span>
                    @if (filled($c->comentari_professor))<span style="opacity: .7;">· {{ \Illuminate\Support\Str::limit($c->comentari_professor, 120) }}</span>@endif
                </div>
            @endforeach
        </div>
    @endif
    <div style="font-size: .75rem; opacity: .7;">
        {{ $legend ?? 'Verd: bé amb la versió vigent · verd clar amb número: bé amb una versió anterior · taronja o vermell: ha fallat · gris: mai executada.' }}
    </div>
</div>
