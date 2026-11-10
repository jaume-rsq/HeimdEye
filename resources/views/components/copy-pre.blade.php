{{-- Bloc <pre> amb un botó «Copiar» a la cantonada. Ús: <x-copy-pre :text="$codi" style="..." /> --}}
@props(['text' => ''])
<div style="position: relative">
    <div style="position: absolute; top: .25rem; right: .25rem; z-index: 1">
        <x-filament::icon-button
            icon="heroicon-o-clipboard-document"
            color="gray"
            size="sm"
            label="Copiar"
            tooltip="Copiar"
            x-data
            x-on:click.stop="{{ \App\Filament\Support\CopyCode::js(\Illuminate\Support\Js::from((string) $text)) }}"
        />
    </div>
    <pre {{ $attributes }}>{{ $text }}</pre>
</div>
