{{-- Arrossegar un fitxer CSV omple el camp de text: el fitxer es llegeix al navegador i no es puja enlloc. --}}
<div
    x-data="{ over: false, name: null }"
    x-on:dragover.prevent="over = true"
    x-on:dragleave.prevent="over = false"
    x-on:drop.prevent="
        over = false;
        const file = $event.dataTransfer.files[0];
        if (! file) return;
        name = file.name;
        file.text().then(text => $wire.set('data.text', text));
    "
    :style="over ? { borderColor: 'rgb(245,158,11)', background: 'rgba(245,158,11,.08)' } : {}"
    style="border: 2px dashed rgba(128,128,128,.35); border-radius: .75rem; padding: 1rem; text-align: center; font-size: .875rem"
>
    <span x-show="! name">Arrossega aquí el fitxer CSV, o enganxa la llista al camp de sota.</span>
    <span x-show="name" x-text="'Fitxer carregat: ' + name"></span>
    <label style="display: block; margin-top: .4rem; font-size: .8rem; color: rgb(217,119,6); cursor: pointer">
        o tria'l
        <input type="file" accept=".csv,.tsv,.txt,text/csv,text/plain" style="display: none"
            x-on:change="const file = $event.target.files[0]; if (file) { name = file.name; file.text().then(text => $wire.set('data.text', text)); }" />
    </label>
</div>
