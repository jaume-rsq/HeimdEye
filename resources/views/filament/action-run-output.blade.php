{{-- Detall d'una execució d'acció: paràmetres i sortida (amb els secrets ja emmascarats). Estils en línia: el tema de Filament no compila classes noves. --}}
@include('filament.partials.detail-styles')
<div class="he-detail">
    <dl>
        <dt>Versió</dt><dd>v{{ $run->version?->versio ?? '?' }}</dd>
        <dt>Estat</dt><dd>{{ $run->estat->value }}{{ $run->es_simulacio ? ' (simulació)' : '' }}</dd>
        <dt>Llançat per</dt><dd>{{ $run->triggeredBy?->nom ?? 'consola' }}</dd>
        <dt>ok / changed / failed</dt><dd>{{ $run->ok ?? '—' }} / {{ $run->changed ?? '—' }} / {{ $run->failed ?? '—' }}</dd>
        <dt>Inici</dt><dd>{{ $run->data_inici?->format('d/m/Y H:i:s') }}</dd>
        <dt>Fi</dt><dd>{{ $run->data_fi?->format('d/m/Y H:i:s') }}</dd>
    </dl>

    @if ($run->parametres)
        <h4>Paràmetres</h4>
        <pre>@foreach ($run->parametres as $name => $value){{ $name }} = {{ $value }}
@endforeach</pre>
    @endif

    <h4>Sortida</h4>
    <pre>{{ $run->sortida ?: '(buida)' }}</pre>
</div>
