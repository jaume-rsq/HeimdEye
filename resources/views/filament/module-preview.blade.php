@php $preview = $getLivewire()->preview(); @endphp
@include('filament.partials.inventory-styles')

<div class="he-inv">
    @if ($preview === null)
        <p class="sub">Tria un alumne per veure com queden els equips i les variables.</p>
    @else
        <div style="display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(26rem, 1fr)); align-items: start">
            <div class="he-inv-wrap">
                <table>
                    <thead><tr><th class="fix">Equip</th><th>Nom de host</th><th>Adreça</th></tr></thead>
                    <tbody>
                        @forelse ($preview['hosts'] as $host)
                            <tr style="cursor: default">
                                <td class="fix"><strong>{{ $host['tipus']->nom }}</strong>@if ($host['tipus']->isUnique()) <span class="sub">únic</span>@endif @if ($host['tipus']->nivell !== \App\Enums\Level::Module) <span class="sub">{{ $host['tipus']->nivell->value }}</span>@endif</td>
                                <td @class(['bad' => $host['problemes']])>{{ $host['nom'] }}</td>
                                <td @class(['mono', 'bad' => $host['problemes']])>
                                    {{ $host['adreca'] }}
                                    @foreach ($host['problemes'] as $problem)<div class="sub">{{ $problem }}</div>@endforeach
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="sub">Cap tipus d'equip.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="he-inv-wrap">
                <table>
                    <thead><tr><th class="fix">Variable</th><th>Valor</th><th>Origen</th></tr></thead>
                    <tbody>
                        @foreach (\App\Support\InventoryGrid::groups(array_keys($preview['vars'])) as $group => $names)
                            <tr class="grp-row" style="cursor: default"><td class="fix" colspan="3">{{ $group }}</td></tr>
                            @foreach ($names as $key)
                                @php $var = $preview['vars'][$key]; @endphp
                                <tr style="cursor: default">
                                    <td class="fix mono">{{ $key }}</td>
                                    <td @class(['mono', 'o-'.$var['origen'], 'bad' => $var['error'] || str_contains($var['valor'], '{' . '{')]) >{{ $var['valor'] }}@if ($var['error'])<div class="sub">{{ $var['error'] }}</div>@endif</td>
                                    <td class="sub">{{ ['global' => 'global', 'curs' => 'del curs', 'modul' => 'del mòdul', 'alumne' => 'camp de l\'alumne', 'propia' => 'pròpia de l\'alumne'][$var['origen']] }}</td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
