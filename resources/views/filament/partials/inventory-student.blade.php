{{-- Panell lateral d'un alumne a l'Inventari: què rep i d'on surt. --}}
@php
    $panel = $this->studentPanel($studentId);
    $origins = ['global' => 'Global', 'curs' => 'Curs', 'modul' => 'Mòdul', 'alumne' => 'Alumne', 'propia' => 'Alumne'];
@endphp
@include('filament.partials.inventory-styles')

<div class="he-inv" x-data="{ tab: 'variables' }">
    @if ($panel === null)
        <p class="sub">Aquest alumne no és del mòdul triat.</p>
    @else
        @php
            $student = $panel['student'];
            $editable = $this->canEditStudent($student);
        @endphp
        <div class="he-inv-bar">
            <div class="he-inv-tabs">
                @foreach (['variables' => 'Variables', 'equips' => 'Equips', 'ansible' => 'Inventari Ansible', 'activitat' => 'Activitat'] as $key => $label)
                    <button type="button" x-on:click="tab = @js($key)" :class="{ on: tab === @js($key) }">{{ $label }}</button>
                @endforeach
            </div>
            @if ($editable)
                <x-filament::button size="sm" color="gray" icon="heroicon-o-plus" wire:click="mountAction('editStudentVariable', { alumne: {{ $student->id }} })">Variable pròpia</x-filament::button>
            @endif
        </div>

        <div x-show="tab === 'variables'">
            <div class="he-inv-wrap">
                <table class="he-levels">
                    <thead><tr><th class="fix">Variable</th><th>Global</th><th>Curs</th><th>Mòdul</th><th>Alumne</th><th>Efectiu</th></tr></thead>
                    <tbody>
                        @foreach (\App\Support\InventoryGrid::groups(array_keys($panel['vars'])) as $group => $names)
                            <tr class="grp-row"><td class="fix" colspan="6">{{ $group }}</td></tr>
                            @foreach ($names as $key)
                                @php
                                    $var = $panel['vars'][$key];
                                    $chain = $panel['chain'][$key] ?? [];
                                    $mine = $chain['propia'] ?? $chain['alumne'] ?? null;
                                @endphp
                                <tr wire:key="pv-{{ $key }}">
                                    <td class="fix mono">{{ $key }}</td>
                                    @foreach (['global', 'curs', 'modul'] as $origin)
                                        <td @class(['mono', 'he-win' => $var['origen'] === $origin, 'he-hidden' => isset($chain[$origin]) && $var['origen'] !== $origin])>{{ $chain[$origin] ?? '' }}</td>
                                    @endforeach
                                    <td
                                        @class(['mono', 'he-cell', 'o-propia' => isset($chain['propia']), 'he-win' => in_array($var['origen'], ['alumne', 'propia']), 'he-click' => $editable && ! isset($chain['alumne'])])
                                        @if ($editable && ! isset($chain['alumne'])) wire:click="mountAction('editStudentVariable', @js(['alumne' => $student->id, 'clau' => $key]))" @endif
                                        title="{{ isset($chain['alumne']) ? 'Camp de la fitxa de l\'alumne' : (isset($chain['propia']) ? 'Variable pròpia de l\'alumne' : '') }}"
                                    >
                                        @if ($mine !== null){{ $mine }}@elseif ($editable)<span class="sub">+</span>@endif
                                    </td>
                                    <td @class(['mono', 'bad' => $var['error'] || str_contains($var['valor'], '{' . '{')]) title="{{ $var['error'] ?? '' }}">
                                        <strong>{{ $var['valor'] }}</strong>
                                        @if ($var['error'])<div class="sub he-msg">{{ $var['error'] }}</div>@endif
                                    </td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="legend">
                <span><strong>negreta</strong> = el que mana</span>
                <span class="he-hidden">ratllat</span> <span>= tapat per un nivell més concret</span>
                <span class="o-propia">pròpia de l'alumne</span>
                @if ($editable)<span>Un clic a la columna Alumne afegeix o canvia una excepció per a aquest alumne.</span>@endif
            </div>
        </div>

        <div x-show="tab === 'equips'" x-cloak>
            <div class="he-inv-wrap">
                <table>
                    <thead><tr><th class="fix">Equip</th><th>Nivell</th><th>Host</th><th>Adreça</th><th>Accés</th><th>Connexió</th></tr></thead>
                    <tbody>
                        @foreach ([...$panel['hosts'], ...$panel['globals']] as $host)
                            @php $type = $host['tipus']; @endphp
                            <tr style="cursor: default">
                                <td class="fix"><strong>{{ $type->nom }}</strong> <span class="sub mono">{{ $type->grup_ansible }}</span></td>
                                <td class="sub">{{ $origins[$type->nivell->value] }}{{ $type->isUnique() ? ', únic' : '' }}</td>
                                <td @class(['bad' => $host['problemes']])>{{ $host['nom'] }}</td>
                                <td @class(['mono', 'bad' => $host['problemes']])>
                                    {{ $host['adreca'] }}
                                    @foreach ($host['problemes'] as $problem)<div class="sub">{{ $problem }}</div>@endforeach
                                </td>
                                <td>
                                    @if ($type->access)
                                        <span class="mono">{{ $type->access->clau }}</span> <span class="sub">(de l'equip)</span>
                                    @elseif ($panel['access'])
                                        <span class="mono">{{ $panel['access']->clau }}</span> <span class="sub">(del mòdul)</span>
                                    @else
                                        <span class="sub">—</span>
                                    @endif
                                    @foreach ($type->variables_connexio ?? [] as $k => $v)<div class="mono sub">{{ $k }}={{ is_scalar($v) ? $v : json_encode($v) }}</div>@endforeach
                                </td>
                                <td>@include('filament.partials.connection-dot', ['studentId' => $type->isUnique() ? null : $student->id, 'type' => $type, 'title' => 'Provar la connexió amb aquest equip'])</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div x-show="tab === 'ansible'" x-cloak>
            <p class="sub" style="margin-bottom: .5rem">L'inventari que rep el runner per a aquest alumne amb tots els equips. Les contrasenyes surten emmascarades i els fitxers de clau només existeixen durant l'execució.</p>
            <pre class="mono" style="margin: 0; max-height: 60vh; overflow: auto; border-radius: .5rem; padding: .75rem; background: #0b1020; color: #e5e7eb; font-size: .75rem; line-height: 1.4">{{ $panel['ansible'] }}</pre>
        </div>

        <div x-show="tab === 'activitat'" x-cloak style="display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(18rem, 1fr))">
            <div>
                <h4 style="font-weight: 600; margin-bottom: .4rem">Darreres accions</h4>
                @forelse ($panel['actions'] as $run)
                    <div style="display: flex; gap: .5rem; padding: .25rem 0; border-bottom: 1px solid rgba(128,128,128,.15)">
                        <x-filament::badge :color="$run->estat->getColor()">{{ $run->estat->getLabel() }}</x-filament::badge>
                        <span>{{ $run->action->nom }}</span>
                        <span class="sub" style="margin-left: auto">{{ $run->data_inici?->format('d/m H:i') }}</span>
                    </div>
                @empty
                    <p class="sub">Cap execució.</p>
                @endforelse
            </div>
            <div>
                <h4 style="font-weight: 600; margin-bottom: .4rem">Checks</h4>
                @forelse ($panel['checks'] as $state)
                    <div style="display: flex; gap: .5rem; padding: .25rem 0; border-bottom: 1px solid rgba(128,128,128,.15)">
                        <x-filament::badge :color="$state->isPassing() ? 'success' : 'danger'">{{ $state->isPassing() ? 'passa' : 'no passa' }}</x-filament::badge>
                        <span>{{ $state->check->nom }}</span>
                        <span class="sub" style="margin-left: auto">{{ $state->data_darrera_execucio?->format('d/m H:i') }}</span>
                    </div>
                @empty
                    <p class="sub">Encara no s'ha executat cap check.</p>
                @endforelse
            </div>
        </div>
    @endif
</div>
