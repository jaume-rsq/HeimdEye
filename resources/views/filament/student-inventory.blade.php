@php
    $page = $getLivewire();
    $modules = $page->inventoryModules();
    $data = $page->inventory();
    $tab = $page->pestanya;
    $tabs = ['variables' => 'Variables', 'equips' => 'Equips', 'ansible' => 'Inventari Ansible', 'activitat' => 'Activitat'];
@endphp
@include('filament.partials.inventory-styles')

<div class="he-inv">
    <div class="he-inv-bar">
        @if ($modules->count() > 1)
            <select wire:model.live="modul" aria-label="Mòdul">
                @foreach ($modules as $module)
                    <option value="{{ $module->id }}">{{ trim("{$module->codi} {$module->nom}") }}</option>
                @endforeach
            </select>
        @elseif ($modules->count() === 1)
            <span class="chip">{{ trim("{$modules->first()->codi} {$modules->first()->nom}") }}</span>
        @endif
        <div class="he-inv-tabs">
            @foreach ($tabs as $key => $label)
                <button type="button" wire:click="$set('pestanya', '{{ $key }}')" @class(['on' => $tab === $key])>{{ $label }}</button>
            @endforeach
        </div>
    </div>

    @if ($data === null)
        <p class="sub">L'alumne no està matriculat a cap mòdul.</p>
    @elseif ($tab === 'variables')
        <div class="he-inv-wrap">
            <table>
                <thead><tr><th class="fix">Variable</th><th>Valor</th><th>Origen</th></tr></thead>
                <tbody>
                    @foreach (\App\Support\InventoryGrid::groups(array_keys($data['vars'])) as $group => $names)
                        <tr class="grp-row" style="cursor: default"><td class="fix" colspan="3">{{ $group }}</td></tr>
                        @foreach ($names as $key)
                            @php $var = $data['vars'][$key]; @endphp
                            <tr style="cursor: default">
                                <td class="fix mono">{{ $key }}</td>
                                <td @class(['mono', 'o-'.$var['origen'], 'bad' => $var['error'] || str_contains($var['valor'], '{' . '{')])>{{ $var['valor'] }}</td>
                                <td class="sub">
                                    {{ ['global' => 'global', 'curs' => 'del curs', 'modul' => 'del mòdul', 'alumne' => 'camp de l\'alumne', 'propia' => 'pròpia de l\'alumne'][$var['origen']] }}
                                    @if ($var['tapa']) · tapa un valor inferior @endif
                                    @if ($var['error']) · <span style="color: rgb(220,38,38)">{{ $var['error'] }}</span> @endif
                                </td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    @elseif ($tab === 'equips')
        <div class="he-inv-wrap">
            <table>
                <thead><tr><th class="fix">Tipus</th><th>Grup Ansible</th><th>Nom de host</th><th>Adreça</th><th>Connexió</th><th>Credencial</th></tr></thead>
                <tbody>
                    @foreach ([...$data['hosts'], ...$data['globals']] as $host)
                        @php $type = $host['tipus']; @endphp
                        <tr style="cursor: default">
                            <td class="fix"><strong>{{ $type->nom }}</strong>@if ($type->isUnique()) <span class="sub">únic</span>@endif @if ($type->nivell !== \App\Enums\Level::Module) <span class="sub">{{ $type->nivell->value }}</span>@endif</td>
                            <td class="mono">{{ $type->grup_ansible }}</td>
                            <td @class(['bad' => $host['problemes']])>{{ $host['nom'] }}</td>
                            <td @class(['mono', 'bad' => $host['problemes']])>
                                {{ $host['adreca'] }}
                                @foreach ($host['problemes'] as $problem)<div class="sub">{{ $problem }}</div>@endforeach
                            </td>
                            <td class="mono sub">
                                @foreach ($type->variables_connexio ?? [] as $k => $v)<div>{{ $k }}={{ is_scalar($v) ? var_export($v, true) : json_encode($v) }}</div>@endforeach
                            </td>
                            <td>{{ $type->access?->clau ?? ($data['access'] ? $data['access']->clau.' (del mòdul)' : '—') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @elseif ($tab === 'ansible')
        <p class="sub" style="margin-bottom: .5rem">L'inventari que rep el runner per a aquest alumne amb tots els equips. Les contrasenyes surten emmascarades i els fitxers de clau només existeixen durant l'execució.</p>
        <pre class="mono" style="margin: 0; max-height: 60vh; overflow: auto; border-radius: .5rem; padding: .75rem; background: #0b1020; color: #e5e7eb; font-size: .75rem; line-height: 1.4">{{ $data['ansible'] }}</pre>
    @else
        <div style="display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(20rem, 1fr))">
            <div>
                <h4 style="font-weight: 600; margin-bottom: .4rem">Darreres accions</h4>
                @forelse ($data['actions'] as $run)
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
                @forelse ($data['checks'] as $state)
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
