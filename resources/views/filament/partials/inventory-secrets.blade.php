@php
    $levels = $this->levels();
    $editable = $this->canEditSecrets();
@endphp
<div class="he-inv-wrap">
    <table class="he-levels">
        <thead>
            <tr>
                <th class="fix">Secret</th>
                @foreach ($levels as $level)
                    <th>{{ $this->levelLabel($level) }}</th>
                @endforeach
                <th>Accés de</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($this->secretMatrix as $key => $cells)
                @php
                    $win = $this->winner($cells);
                    $haystack = mb_strtolower($key.' '.collect($cells)->map(fn ($s) => $s->nom_usuari.' '.$s->descripcio)->implode(' '));
                @endphp
                <tr wire:key="sec-{{ $key }}" x-show="@js($haystack).includes(search.trim().toLowerCase())">
                    <td class="fix">
                        <span class="mono">{{ $key }}</span>
                        @if ($desc = $cells[$win]?->descripcio)
                            <div class="sub" style="white-space: normal; max-width: 18rem">{{ $desc }}</div>
                        @endif
                    </td>
                    @foreach ($levels as $level)
                        @php $secret = $cells[$level->value] ?? null; @endphp
                        <td
                            @class(['he-cell', 'he-win' => $secret && $level->value === $win, 'he-hidden' => $secret && $level->value !== $win, 'he-empty' => ! $secret, 'he-click' => $editable])
                            @if ($editable) wire:click="mountAction('editSecret', @js(['clau' => $key, 'nivell' => $level->value]))" @endif
                        >
                            @if ($secret)
                                {{ $secret->tipus->label() }}@if ($secret->nom_usuari) · <span class="mono">{{ $secret->nom_usuari }}</span>@endif
                                <div @class(['sub', 'bad' => ! $secret->hasValue()])>{{ $secret->hasValue() ? 'valor desat' : 'sense valor' }}{{ filled($secret->clau_publica) ? ' · clau pública' : '' }}</div>
                            @elseif ($editable)
                                <span class="sub">+</span>
                            @endif
                        </td>
                    @endforeach
                    <td class="sub">{{ \App\Filament\Resources\Secrets\Schemas\SecretFields::usage($cells[$win]) ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="{{ count($levels) + 2 }}">No hi ha cap secret.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="legend">
    <span><strong>negreta</strong> = el que mana</span>
    <span class="he-hidden">ratllat</span> <span>= tapat per un nivell més concret</span>
</div>
@include('filament.secret-help')
