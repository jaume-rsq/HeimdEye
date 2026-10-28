@php
    $module = $getLivewire()->getRecord();
    $inherited = $module?->visibleHostTypes()->where('nivell', '!=', \App\Enums\Level::Module) ?? collect();
@endphp
<div style="font-size: .8rem; opacity: .75; line-height: 1.5">
    @if ($inherited->isNotEmpty())
        El mòdul també fa servir els equips comuns (Inventari → Equips comuns):
        @foreach ($inherited as $type)<code>{{ $type->nom }}</code> ({{ $type->nivell->value }})@if (! $loop->last), @endif @endforeach.
        Un d'aquí amb el mateix nom el tapa.
    @else
        Els equips que són iguals per a tots els mòduls (o per a tot un curs) es poden definir una sola vegada a Inventari → Equips comuns.
    @endif
</div>
