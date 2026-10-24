{{-- Torna a la pantalla anterior; si s'hi ha arribat amb un enllaç directe, va a l'inici del panell. --}}
<div class="flex justify-start">
    <x-filament::button
        color="gray"
        icon="heroicon-m-arrow-left"
        tag="a"
        href="{{ filament()->getUrl() }}"
        x-on:click="if (document.referrer.startsWith(window.location.origin)) { $event.preventDefault(); history.back() }"
    >
        Tornar
    </x-filament::button>
</div>
