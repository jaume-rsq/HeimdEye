{{-- Estat de la prova de connexió d'un equip i botó per provar-lo. --}}
@php $dot = $this->connectionDot($studentId, $type); @endphp
<span class="he-conn">
    @if ($dot)
        <span @class(['he-dot', 'he-pending' => $dot['estat'] === 'pendent']) style="background: {{ $dot['color'] }}" title="{{ $dot['etiqueta'] }}: {{ $dot['missatge'] }}"></span>
    @endif
    <button type="button" class="he-try" wire:click.stop="testConnection({{ $studentId ?? 'null' }}, {{ $type->id }})" title="{{ $title }}">▶</button>
</span>
