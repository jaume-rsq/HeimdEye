{{--
    Capçalera de pàgina amb el títol i, a la dreta, els selectors de grup-classe i de mòdul (mòduls
    amb algun alumne del grup). Els selectors fan wire:model a $grup i $modul de la pàgina.
    Paràmetres: $title, $groups (pot ser buit: no surt), $modules, i opcionalment $actions (HTML).
--}}
<header class="fi-header" style="display: flex; flex-wrap: wrap; gap: .75rem 1.5rem; align-items: center; justify-content: space-between">
    <h1 class="fi-header-heading" style="margin: 0">{{ $title }}</h1>
    <div style="display: flex; flex-wrap: wrap; gap: .5rem; align-items: center">
        @php($style = 'padding: .4rem .65rem; border: 1px solid rgba(128,128,128,.35); border-radius: .5rem; background: transparent; font-size: .875rem; max-width: 100%')
        @if ($groups->isNotEmpty())
            <select wire:model.live="grup" aria-label="Grup" style="{{ $style }}; min-width: 10rem">
                <option value="">Tots els grups</option>
                @foreach ($groups as $g)
                    <option value="{{ $g->id }}">{{ $g->label() }}</option>
                @endforeach
            </select>
        @endif
        @if ($modules->isNotEmpty())
            <select wire:model.live="modul" aria-label="Mòdul" style="{{ $style }}; min-width: 16rem">
                @foreach ($modules as $m)
                    <option value="{{ $m->id }}">{{ $m->label() }}{{ $m->academicYear && ! $m->academicYear->es_actual ? ' ('.$m->academicYear->nom.')' : '' }}</option>
                @endforeach
            </select>
        @endif
        {{ $actions ?? '' }}
    </div>
</header>
