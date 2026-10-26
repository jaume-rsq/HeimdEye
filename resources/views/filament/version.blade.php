{{-- Detall d'una versió d'acció o de check i diferències amb l'anterior ($checks: checks de verificació, null en un check). Estils en línia: el tema de Filament no compila classes noves. --}}
@php
    $diff = $previous && $previous->codi !== $version->codi
        ? (new \SebastianBergmann\Diff\Differ(new \SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder("--- v{$previous->versio}\n+++ v{$version->versio}\n")))->diff($previous->codi, $version->codi)
        : null;
@endphp
@include('filament.partials.detail-styles')
<div class="he-detail" x-data="{ tab: 'codi' }">
    <dl>
        <dt>Creat per</dt><dd>{{ $version->author?->nom ?? 'seeder' }}</dd>
        <dt>Data</dt><dd>{{ $version->created_at?->format('d/m/Y H:i') }}</dd>
        <dt>Temps màxim</dt><dd>{{ $version->temps_max_seg }} s</dd>
        @if ($checks !== null)
            <dt>Checks de verificació</dt><dd>{{ $checks->implode(', ') ?: 'cap' }}</dd>
        @endif
    </dl>

    @if (filled($version->comentari))
        <h4>Comentari</h4>
        <div>{{ $version->comentari }}</div>
    @endif

    @if ($version->parametres)
        <h4>Paràmetres</h4>
        <pre>@foreach ($version->parametres as $param){{ $param['name'] }}{{ filled($param['label'] ?? null) ? " ({$param['label']})" : '' }} = {{ $param['default'] ?? '(sense valor per defecte)' }}
@endforeach</pre>
    @endif

    <div class="tabs">
        <x-filament::button size="xs" x-on:click="tab = 'codi'">Codi</x-filament::button>
        <x-filament::button size="xs" color="gray" x-on:click="tab = 'diff'">Diferències amb la versió anterior</x-filament::button>
    </div>
    <pre x-show="tab === 'codi'">{{ $version->codi }}</pre>
    @if ($diff)
        <pre x-show="tab === 'diff'" x-cloak>@foreach (explode("\n", $diff) as $line)<span @class(['add' => str_starts_with($line, '+'), 'del' => str_starts_with($line, '-'), 'hunk' => str_starts_with($line, '@@')])>{{ $line }}</span>
@endforeach</pre>
    @else
        <div x-show="tab === 'diff'" x-cloak style="opacity: .7;">
            {{ $previous ? "El codi és igual que a v{$previous->versio}: només canvien altres camps." : 'És la primera versió: no hi ha codi anterior.' }}
        </div>
    @endif
</div>
