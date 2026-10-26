{{-- Detall d'una execució de check: missatge i sortida (amb els secrets ja emmascarats). Estils en línia: el tema de Filament no compila classes noves. --}}
@include('filament.partials.detail-styles')
<div class="he-detail">
    <dl>
        <dt>Versió</dt><dd>v{{ $run->checkVersion?->versio ?? '?' }}</dd>
        <dt>Estat</dt><dd>{{ $run->estat->value }}</dd>
        <dt>Origen</dt><dd>{{ $run->batch?->origen?->value }}{{ $run->batch?->triggeredBy ? ' · '.$run->batch->triggeredBy->nom : '' }}</dd>
        <dt>Data</dt><dd>{{ $run->data_execucio?->format('d/m/Y H:i:s') }}</dd>
        <dt>Durada</dt><dd>{{ $run->durada_ms === null ? '—' : round($run->durada_ms / 1000, 1).' s' }}</dd>
        <dt>Codi de sortida</dt><dd>{{ $run->codi_sortida ?? '—' }}</dd>
    </dl>

    <h4>Missatge</h4>
    <div>{{ $run->missatge ?: '(cap)' }}</div>

    <h4>Sortida</h4>
    <pre>{{ $run->sortida ?: '(buida)' }}</pre>
</div>
