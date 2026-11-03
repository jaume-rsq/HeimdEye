{{--
    Capçalera de l'Inventari: el títol i, al costat, les pestanyes (Alumnes, Equips, Variables,
    Secrets); a la dreta, el curs i el mòdul. Fa wire:model a $curs, $modul i $pestanya de la pàgina.
--}}
@php
    $pestanya = $this->pestanya;
    $style = 'padding: .4rem .65rem; border: 1px solid rgba(128,128,128,.35); border-radius: .5rem; background: transparent; font-size: .875rem; max-width: 100%';
    $label = 'display: flex; gap: .5rem; align-items: center; font-size: .875rem; font-weight: 600; max-width: 100%';
@endphp
@include('filament.partials.inventory-styles')
<header class="fi-header" style="display: flex; flex-wrap: wrap; gap: .75rem 1.5rem; align-items: center; justify-content: space-between">
    <div style="display: flex; flex-wrap: wrap; gap: .75rem 1.5rem; align-items: center">
        <h1 class="fi-header-heading" style="margin: 0">{{ $title }}</h1>
        <div class="he-inv-tabs" role="tablist">
            <button type="button" role="tab" wire:click="$set('pestanya', 'alumnes')" @class(['on' => $pestanya === 'alumnes'])>Alumnes</button>
            <button type="button" role="tab" wire:click="$set('pestanya', 'equips')" @class(['on' => $pestanya === 'equips'])>Equips</button>
            <button type="button" role="tab" wire:click="$set('pestanya', 'variables')" @class(['on' => $pestanya === 'variables'])>Variables</button>
            @if ($this->canSeeSecrets())
                <button type="button" role="tab" wire:click="$set('pestanya', 'secrets')" @class(['on' => $pestanya === 'secrets'])>Secrets</button>
            @endif
        </div>
    </div>
    <div style="display: flex; flex-wrap: wrap; gap: .5rem 1rem; align-items: center">
        <label style="{{ $label }}">Curs
            <select wire:model.live="curs" style="{{ $style }}; min-width: 7rem">
                @foreach ($this->courses as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>
        <label style="{{ $label }}">Mòdul
            <select wire:model.live="modul" wire:key="inv-moduls-{{ $this->curs }}" style="{{ $style }}; min-width: 22rem">
                @foreach ($this->modules as $id => $name)
                    <option value="{{ $id }}" @selected($this->modul === $id)>{{ $name }}</option>
                @endforeach
                <option value="0" @selected($this->modul === 0)>Només comuns (global i curs)</option>
            </select>
        </label>
    </div>
</header>
