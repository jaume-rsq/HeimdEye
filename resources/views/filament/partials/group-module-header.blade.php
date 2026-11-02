{{--
    Capçalera de pàgina amb el títol i, a la dreta, els selectors de grup-classe i de mòdul (mòduls
    amb algun alumne del grup). Els selectors fan wire:model a $grup i $modul de la pàgina.
    Paràmetres: $title, $groups (pot ser buit: no surt), $modules, i opcionalment $actions (HTML).
    Cada selector porta el seu títol al davant.
--}}
<header class="fi-header" style="display: flex; flex-wrap: wrap; gap: .75rem 1.5rem; align-items: center; justify-content: space-between">
    <h1 class="fi-header-heading" style="margin: 0">{{ $title }}</h1>
    <div style="display: flex; flex-wrap: wrap; gap: .5rem 1rem; align-items: center">
        @php($style = 'padding: .4rem .65rem; border: 1px solid rgba(128,128,128,.35); border-radius: .5rem; background: transparent; font-size: .875rem; max-width: 100%')
        @php($label = 'display: flex; gap: .5rem; align-items: center; font-size: .875rem; font-weight: 600; max-width: 100%')
        @if ($groups->isNotEmpty())
            <label style="{{ $label }}">Grup
            <select wire:model.live="grup" style="{{ $style }}; min-width: 10rem">
                <option value="">Tots els grups</option>
                @foreach ($groups as $g)
                    <option value="{{ $g->id }}">{{ $g->label() }}</option>
                @endforeach
            </select>
            </label>
        @endif
        @if ($modules->isNotEmpty())
            <label style="{{ $label }}">Mòdul
            <select wire:model.live="modul" style="{{ $style }}; min-width: 16rem">
                @foreach ($modules as $m)
                    <option value="{{ $m->id }}">{{ $m->label() }}{{ $m->academicYear && ! $m->academicYear->es_actual ? ' ('.$m->academicYear->nom.')' : '' }}</option>
                @endforeach
            </select>
            </label>
        @endif
        {{ $actions ?? '' }}
    </div>
</header>
