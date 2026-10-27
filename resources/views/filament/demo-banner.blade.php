{{-- Avís del mode demo (App\Support\Demo). --}}
@if (\App\Support\Demo::enabled())
    <div style="margin-bottom: 1rem; padding: .75rem 1rem; border-radius: .5rem; border: 1px solid #3b82f6; background: rgba(59, 130, 246, .12); font-size: .875rem;">
        <strong>Entorn de demostració.</strong> Els alumnes són ficticis i tot s'esborra cada nit: no hi posis dades reals.
        Pots crear i provar checks i accions; l'estructura, l'inventari i el que han creat altres visitants són només de lectura.
    </div>
@endif
