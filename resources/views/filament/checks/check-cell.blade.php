@php
    /** @var \App\Models\Check $record */
    $active = $record->isActiveOn();
    $activeTip = match (true) {
        $record->es_actiu !== null => $record->es_actiu ? 'Actiu (forçat)' : 'Inactiu (forçat)',
        default => 'Segons les dates: '.($record->actiu_des?->format('d/m/Y') ?? '…').' – '.($record->actiu_fins?->format('d/m/Y') ?? '…'),
    };
    $chip = 'display: inline-flex; align-items: center; gap: .25rem; padding: .05rem .5rem; border-radius: .4rem; border: 1px solid rgba(128,128,128,.28); font-size: .75rem; white-space: nowrap';
@endphp
<div style="display: grid; gap: .35rem; padding: .35rem 0; min-width: 24rem; font-size: .875rem">
    <div>
        <strong>{{ $record->nom }}</strong>
        @if (filled($record->descripcio))
            <div title="{{ $record->descripcio }}" style="opacity: .7; font-size: .8rem; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; white-space: normal">{{ $record->descripcio }}</div>
        @endif
    </div>
    <div style="display: flex; flex-wrap: wrap; gap: .35rem">
        <span style="{{ $chip }}" title="Bloc {{ $record->task->block->numero }}: {{ $record->task->block->titol }} · {{ $record->task->block->module->nom }}">
            <span style="opacity: .6">Tasca</span> {{ $record->task->titol }}
            <span style="opacity: .6">· B{{ $record->task->block->numero }} · {{ $record->task->block->module->nom }}</span>
        </span>
        <span style="{{ $chip }}"><span style="opacity: .6">Executor</span> {{ $record->executor->value }}</span>
        <span style="{{ $chip }}"><span style="opacity: .6">Punts</span> {{ $record->punts }}{{ $record->tipus === \App\Enums\CheckKind::Optional ? ' · opcional' : '' }}</span>
        <span style="{{ $chip }}" title="{{ $activeTip }}">
            <span style="width: .5rem; height: .5rem; border-radius: 999px; background: {{ $active ? 'rgb(22,163,74)' : 'rgb(156,163,175)' }}"></span>
            {{ $active ? 'Actiu' : 'Inactiu' }}
        </span>
        <span style="{{ $chip }}"><span style="opacity: .6">Versió</span> {{ $record->currentVersion ? 'v'.$record->currentVersion->versio : 'sense codi' }}</span>
        @if (filled($record->info))
            <span style="{{ $chip }}" title="Té informació per a l'alumne">ⓘ info</span>
        @endif
    </div>
</div>
