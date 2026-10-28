<div style="font-size: .8rem; opacity: .75; line-height: 1.5">
    Les variables i els secrets del mòdul (i els comuns) es gestionen a
    <a href="{{ \App\Filament\Pages\InventoryOverview::getUrl(['modul' => $moduleId ?? null, 'pestanya' => 'variables']) }}" style="text-decoration: underline">Inventari → Variables</a>
    i <a href="{{ \App\Filament\Pages\InventoryOverview::getUrl(['modul' => $moduleId ?? null, 'pestanya' => 'secrets']) }}" style="text-decoration: underline">Inventari → Secrets</a>.
</div>
