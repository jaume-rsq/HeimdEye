@php
    $levels = $this->levels();
    $editable = $this->canEditHostTypes();
@endphp
<div class="he-inv-wrap">
    <table class="he-levels">
        <thead>
            <tr>
                <th class="fix">Tipus d'equip</th>
                @foreach ($levels as $level)
                    <th>{{ $this->levelLabel($level) }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($this->hostTypeMatrix as $name => $cells)
                @php
                    $win = $this->winner($cells);
                    $haystack = mb_strtolower($name.' '.collect($cells)->map(fn ($t) => $t->grup_ansible.' '.$t->nom_host.' '.$t->adreca)->implode(' '));
                @endphp
                <tr wire:key="type-{{ $name }}" x-show="@js($haystack).includes(search.trim().toLowerCase())">
                    <td class="fix">
                        <span class="mono">{{ $name }}</span>
                        <div class="sub">grup Ansible {{ $cells[$win]->grup_ansible }}{{ $cells[$win]->isUnique() ? ' · equip únic' : '' }}</div>
                    </td>
                    @foreach ($levels as $level)
                        @php $type = $cells[$level->value] ?? null; @endphp
                        <td
                            @class(['he-cell', 'he-win' => $type && $level->value === $win, 'he-hidden' => $type && $level->value !== $win, 'he-empty' => ! $type, 'he-click' => $editable])
                            @if ($editable) wire:click="mountAction('editHostType', @js(['nom' => $name, 'nivell' => $level->value]))" @endif
                        >
                            @if ($type)
                                <span class="mono">{{ $type->nom_host }}</span> → <span class="mono">{{ $type->adreca }}</span>
                                <div class="sub">
                                    {{ $type->isUnique() ? 'únic' : 'un per alumne' }}
                                    · accés {{ $type->access?->clau ?? ($level === \App\Enums\Level::Module ? 'del mòdul' : 'de cada mòdul') }}
                                    @if ($type->variables_connexio)
                                        · {{ count($type->variables_connexio) }} {{ count($type->variables_connexio) === 1 ? 'variable' : 'variables' }}
                                    @endif
                                </div>
                            @elseif ($editable)
                                <span class="sub">+</span>
                            @endif
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($levels) + 1 }}">No hi ha cap tipus d'equip.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="legend">
    <span><strong>negreta</strong> = el que mana</span>
    <span class="he-hidden">ratllat</span> <span>= tapat per un nivell més concret amb el mateix nom</span>
</div>
<div class="legend">
    <span>Un equip únic és un sol equip per a tothom (p. ex. el servidor Zabbix); els altres, un per alumne, amb el nom i l'adreça calculats amb les seves variables (pestanya Alumnes).</span>
</div>
