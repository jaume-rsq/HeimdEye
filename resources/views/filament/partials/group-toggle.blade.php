{{--
    A la capçalera de la columna d'accions d'una taula agrupada per bloc: la casella «No agrupar per
    bloc» i el botó ⇅ que inverteix l'ordre dels blocs. Substitueixen el desplegable d'agrupar de
    Filament (groupingSettingsHidden). Paràmetres: $group (id del grup de la taula).
    També estreny el camp de cerca, perquè càpiga a la línia dels filtres actius.
--}}
<style>
    .fi-ta-header-toolbar .fi-ta-search-field { max-width: 14rem; }
    .he-group-toggle { display: flex; gap: .6rem; align-items: center; justify-content: flex-end; font-weight: 400; text-transform: none; letter-spacing: normal; white-space: nowrap; }
    .he-group-toggle label { display: inline-flex; gap: .35rem; align-items: center; cursor: pointer; }
</style>
<div
    class="he-group-toggle"
    x-data="{ grouping: $wire.$entangle('tableGrouping', true) }"
>
    <label>
        <input type="checkbox" class="fi-checkbox-input" x-bind:checked="! grouping" x-on:change="grouping = $event.target.checked ? '' : @js($group . ':asc')" />
        No agrupar per bloc
    </label>
    <button
        type="button"
        x-show="grouping"
        x-on:click="grouping = String(grouping).endsWith(':desc') ? @js($group . ':asc') : @js($group . ':desc')"
        x-bind:title="String(grouping).endsWith(':desc') ? 'Blocs del més nou al més antic: clica per invertir' : 'Blocs del més antic al més nou: clica per invertir'"
        aria-label="Invertir l'ordre dels blocs"
        style="display: inline-flex; padding: .2rem; border-radius: .375rem"
    >
        <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::ArrowsUpDown" style="width: 1.15rem; height: 1.15rem" />
    </button>
</div>
