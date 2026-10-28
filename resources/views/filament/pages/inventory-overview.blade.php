<x-filament-panels::page>
    @include('filament.partials.inventory-styles')
    @php
        $grid = $this->grid;
        $hidden = ['usuari', 'nom_complet', 'num_taula'];
        // Columnes de variables, agrupades per prefix: [nom, etiqueta del grup si és la primera del grup].
        $columns = [];
        foreach ($grid['groups'] ?? [] as $label => $names) {
            $first = true;
            foreach (array_diff($names, $hidden) as $name) {
                $columns[] = [$name, $first ? $label : null];
                $first = false;
            }
        }
    @endphp

    <div class="he-inv" x-data="{ search: '' }" @if ($provesPendents) wire:poll.2s="refreshConnection" @endif>
        <div class="he-inv-bar">
            <select wire:model.live="modul" aria-label="Mòdul">
                @foreach ($this->modules as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
                <option value="0">Només comuns (global i curs)</option>
            </select>
            <div class="he-inv-tabs">
                <button type="button" wire:click="$set('pestanya', 'alumnes')" @class(['on' => $pestanya === 'alumnes'])>Alumnes</button>
                <button type="button" wire:click="$set('pestanya', 'equips')" @class(['on' => $pestanya === 'equips'])>Equips</button>
                <button type="button" wire:click="$set('pestanya', 'variables')" @class(['on' => $pestanya === 'variables'])>Variables</button>
                @if ($this->canSeeSecrets())
                    <button type="button" wire:click="$set('pestanya', 'secrets')" @class(['on' => $pestanya === 'secrets'])>Secrets</button>
                @endif
            </div>
            @if ($pestanya === 'alumnes')
                <div class="he-inv-tabs">
                    <button type="button" wire:click="$set('vista', 'equips')" @class(['on' => $vista === 'equips'])>Equips</button>
                    <button type="button" wire:click="$set('vista', 'variables')" @class(['on' => $vista === 'variables'])>Variables</button>
                </div>
            @endif
            <input type="search" x-model.debounce.200ms="search" placeholder="{{ $pestanya === 'alumnes' ? 'Cerca alumne o valor...' : 'Cerca nom o valor...' }}" style="flex: 1 1 14rem; min-width: 0" />
            @if ($pestanya === 'alumnes')
                <label style="display: flex; gap: .4rem; align-items: center">
                    <input type="checkbox" wire:model.live="problemes" /> Només amb problemes
                </label>
                @if ($grid)
                    @php $total = collect($grid['rows'])->sum('problemes'); @endphp
                    <span @class(['chip', 'bad' => $total > 0])>{{ $total }} {{ $total === 1 ? 'problema' : 'problemes' }}</span>
                @endif
            @elseif ($pestanya === 'equips')
                {{ $this->moduleAccessAction }}
                @if ($this->canEditHostTypes())
                    {{ $this->createHostTypeAction }}
                @endif
            @elseif ($pestanya === 'variables' && $this->canEditVariables())
                {{ $this->createVariableAction }}
            @elseif ($pestanya === 'secrets' && $this->canEditSecrets())
                {{ $this->createSecretAction }}
            @endif
        </div>

        @if ($pestanya === 'equips')
            @include('filament.partials.inventory-host-types')
        @elseif ($pestanya === 'variables')
            @include('filament.partials.inventory-variables')
        @elseif ($pestanya === 'secrets' && $this->canSeeSecrets())
            @include('filament.partials.inventory-secrets')
        @elseif (! $this->module)
            <x-filament::section>Tria un mòdul per veure'n els alumnes.</x-filament::section>
        @elseif ($grid['rows'] === [])
            <x-filament::section>Aquest mòdul no té alumnes actius.</x-filament::section>
        @else
            @if ($grid['globals'] !== [] && $vista === 'equips')
                <div style="margin-bottom: .75rem">
                    <span class="sub">Equips globals:</span>
                    @foreach ($grid['globals'] as $host)
                        <span @class(['chip', 'bad' => $host['problemes']]) title="{{ implode("\n", $host['problemes']) }}">
                            <strong>{{ $host['tipus']->nom }}</strong> · {{ $host['nom'] }} · <span class="mono">{{ $host['adreca'] }}</span>
                            @include('filament.partials.connection-dot', ['studentId' => null, 'type' => $host['tipus'], 'title' => 'Provar la connexió amb aquest equip'])
                        </span>
                    @endforeach
                </div>
            @endif

            <div class="he-inv-wrap">
                <table>
                    <thead>
                        <tr>
                            <th class="fix">Alumne</th>
                            @if ($vista === 'equips')
                                @foreach ($grid['types'] as $type)
                                    <th>
                                        {{ $type->nom }} <span class="sub">{{ $type->grup_ansible }}</span>
                                        <button type="button" class="he-try" wire:click="testConnection(null, {{ $type->id }})" title="Provar aquest equip a tots els alumnes">▶</button>
                                    </th>
                                @endforeach
                            @else
                                @foreach ($columns as [$key, $group])
                                    <th @class(['mono', 'grp' => $group])><span class="sub" style="display: block; font-family: inherit">{{ $group ?? ' ' }}</span>{{ $key }}</th>
                                @endforeach
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($grid['rows'] as $row)
                            @continue($problemes && $row['problemes'] === 0)
                            @php
                                $student = $row['student'];
                                $haystack = mb_strtolower(implode(' ', [
                                    $student->usuari, $student->nom_complet, sprintf('%02d', $student->num_taula),
                                    ...array_map(fn ($h) => $h['nom'].' '.$h['adreca'], $row['hosts']),
                                    ...array_map(fn ($v) => $v['valor'], $row['vars']),
                                ]));
                            @endphp
                            <tr
                                wire:key="inv-{{ $student->id }}"
                                x-show="@js($haystack).includes(search.trim().toLowerCase())"
                                x-on:click="window.location = @js($this->studentUrl($student->id))"
                            >
                                <td class="fix">
                                    <strong>{{ sprintf('%02d', $student->num_taula) }}</strong> · {{ $student->usuari }}
                                    @if ($row['problemes'])
                                        <span class="chip bad" style="margin-left: .4rem">{{ $row['problemes'] }}</span>
                                    @endif
                                    @if ($vista === 'equips')
                                        <button type="button" class="he-try" wire:click.stop="testConnection({{ $student->id }})" title="Provar tots els equips d'aquest alumne">▶</button>
                                    @endif
                                    <div class="sub">{{ $student->nom_complet }}</div>
                                </td>
                                @if ($vista === 'equips')
                                    @foreach ($grid['types'] as $type)
                                        @php $host = $row['hosts'][$type->nom]; @endphp
                                        <td @class(['bad' => $host['problemes']]) title="{{ implode("\n", $host['problemes']) }}">
                                            @php $dot = $this->connectionDot($student->id, $type); @endphp
                                            <div>{{ $host['nom'] }} @include('filament.partials.connection-dot', ['studentId' => $student->id, 'type' => $type, 'title' => 'Provar la connexió amb aquest equip'])</div>
                                            <div class="mono sub">{{ $host['adreca'] }}</div>
                                            @if ($dot && ! in_array($dot['estat'], ['ok', 'pendent']))
                                                <div class="he-msg" style="color: {{ $dot['color'] }}">{{ \Illuminate\Support\Str::limit($dot['missatge'], 90) }}</div>
                                            @endif
                                        </td>
                                    @endforeach
                                @else
                                    @foreach ($columns as [$key, $group])
                                        @php $var = $row['vars'][$key] ?? null; @endphp
                                        <td @class(['mono', 'grp' => $group, 'o-'.($var['origen'] ?? 'none'), 'bad' => $var && ($var['error'] || str_contains($var['valor'], '{' . '{'))]) title="{{ $var ? ($var['error'] ?? $var['origen'].($var['tapa'] ? ' (tapa un valor inferior)' : '')) : 'no definida' }}">
                                            {{ $var['valor'] ?? '—' }}
                                        </td>
                                    @endforeach
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($vista === 'variables')
                <div class="legend">
                    <span class="o-global">■ global</span>
                    <span class="o-curs">■ del curs</span>
                    <span class="o-modul">■ derivada del mòdul</span>
                    <span>■ camp de l'alumne</span>
                    <span class="o-propia">■ pròpia de l'alumne</span>
                </div>
            @else
                <div class="legend"><span class="bad" style="padding: 0 .4rem">vermell</span> = falta una variable o la plantilla no es pot calcular (passa el ratolí per sobre per veure-ho)</div>
                <div class="legend">
                    <span>▶ prova la connexió (una casella, una columna, un alumne o un equip global); no es desa res</span>
                    <span><span class="he-dot" style="background: rgb(22,163,74)"></span> respon</span>
                    <span><span class="he-dot" style="background: rgb(220,38,38)"></span> no respon</span>
                    <span><span class="he-dot" style="background: rgb(234,88,12)"></span> respon però falla</span>
                    <span><span class="he-dot" style="background: rgb(107,114,128)"></span> no s'ha pogut provar</span>
                </div>
            @endif
        @endif
    </div>
</x-filament-panels::page>
