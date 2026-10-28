@php
    $levels = $this->levels();
    $editable = $this->canEditVariables();
@endphp
<div class="he-inv-wrap">
    <table class="he-levels">
        <thead>
            <tr>
                <th class="fix">Variable</th>
                @foreach ($levels as $level)
                    <th>{{ $this->levelLabel($level) }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($this->variableMatrix as $group => $rows)
                <tr class="grp-row"><td colspan="{{ count($levels) + 1 }}">{{ $group }}</td></tr>
                @foreach ($rows as $key => $cells)
                    @php
                        $win = $this->winner($cells);
                        $haystack = mb_strtolower($key.' '.collect($cells)->map(fn ($v) => $v->valor.' '.$v->descripcio)->implode(' '));
                    @endphp
                    <tr wire:key="var-{{ $key }}" x-show="@js($haystack).includes(search.trim().toLowerCase())">
                        <td class="fix">
                            <span class="mono">{{ $key }}</span>
                            @if ($desc = $cells[$win]?->descripcio)
                                <div class="sub" style="white-space: normal; max-width: 18rem">{{ $desc }}</div>
                            @endif
                        </td>
                        @foreach ($levels as $level)
                            @php $var = $cells[$level->value] ?? null; @endphp
                            <td
                                @class(['mono', 'he-cell', 'o-'.$level->value => $var, 'he-win' => $var && $level->value === $win, 'he-hidden' => $var && $level->value !== $win, 'he-empty' => ! $var, 'he-click' => $editable])
                                @if ($editable) wire:click="mountAction('editVariable', @js(['clau' => $key, 'nivell' => $level->value]))" @endif
                                title="{{ $var ? ($level->value === $win ? 'Aquest és el valor que mana' : 'Tapat per un nivell més concret') : ($editable ? 'Crear-la en aquest nivell' : '') }}"
                            >
                                @if ($var)
                                    {{ $var->valor }}
                                @elseif ($editable)
                                    <span class="sub">+</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            @empty
                <tr><td colspan="{{ count($levels) + 1 }}">No hi ha cap variable.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="legend">
    <span><strong>negreta</strong> = el valor que mana</span>
    <span class="he-hidden">ratllat</span> <span>= tapat per un nivell més concret</span>
    <span>Una variable pròpia d'un alumne (fitxa de l'alumne) les tapa totes.</span>
</div>
@include('filament.variable-help')
