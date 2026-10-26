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

    <div class="he-inv" x-data="{ search: '' }">
        <div class="he-inv-bar">
            <select wire:model.live="modul" aria-label="Mòdul">
                @foreach ($this->modules as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
            <div class="he-inv-tabs">
                <button type="button" wire:click="$set('vista', 'equips')" @class(['on' => $vista === 'equips'])>Equips</button>
                <button type="button" wire:click="$set('vista', 'variables')" @class(['on' => $vista === 'variables'])>Variables</button>
            </div>
            <input type="search" x-model.debounce.200ms="search" placeholder="Cerca alumne o valor..." style="flex: 1 1 14rem; min-width: 0" />
            <label style="display: flex; gap: .4rem; align-items: center">
                <input type="checkbox" wire:model.live="problemes" /> Només amb problemes
            </label>
            @if ($grid)
                @php $total = collect($grid['rows'])->sum('problemes'); @endphp
                <span @class(['chip', 'bad' => $total > 0])>{{ $total }} {{ $total === 1 ? 'problema' : 'problemes' }}</span>
            @endif
        </div>

        @if (! $grid)
            <x-filament::section>No hi ha cap mòdul.</x-filament::section>
        @elseif ($grid['rows'] === [])
            <x-filament::section>Aquest mòdul no té alumnes actius.</x-filament::section>
        @else
            @if ($grid['globals'] !== [] && $vista === 'equips')
                <div style="margin-bottom: .75rem">
                    <span class="sub">Equips globals:</span>
                    @foreach ($grid['globals'] as $host)
                        <span @class(['chip', 'bad' => $host['problemes']]) title="{{ implode("\n", $host['problemes']) }}">
                            <strong>{{ $host['tipus']->nom }}</strong> · {{ $host['nom'] }} · <span class="mono">{{ $host['adreca'] }}</span>
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
                                    <th>{{ $type->nom }} <span class="sub">{{ $type->grup_ansible }}</span></th>
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
                                    <div class="sub">{{ $student->nom_complet }}</div>
                                </td>
                                @if ($vista === 'equips')
                                    @foreach ($grid['types'] as $type)
                                        @php $host = $row['hosts'][$type->nom]; @endphp
                                        <td @class(['bad' => $host['problemes']]) title="{{ implode("\n", $host['problemes']) }}">
                                            <div>{{ $host['nom'] }}</div>
                                            <div class="mono sub">{{ $host['adreca'] }}</div>
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
            @endif
        @endif
    </div>
</x-filament-panels::page>
