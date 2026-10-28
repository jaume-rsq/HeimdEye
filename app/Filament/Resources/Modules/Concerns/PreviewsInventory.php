<?php

namespace App\Filament\Resources\Modules\Concerns;

use App\Enums\Level;
use App\Filament\Pages\InventoryOverview;
use App\Models\HostType;
use App\Models\Student;
use App\Support\InventoryGrid;
use App\Support\VariableResolver;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/** Previsualització i botó de vista de conjunt, comuns a l'edició i a la consulta de l'inventari. */
trait PreviewsInventory
{
    protected function getHeaderActions(): array
    {
        return [
            Action::make('conjunt')
                ->label('Inventari')
                ->icon(Heroicon::OutlinedSquares2x2)
                ->color('gray')
                ->url(fn () => InventoryOverview::getUrl(['modul' => $this->record->id])),
        ];
    }

    /** La previsualització comença amb el primer alumne actiu. */
    protected function afterFill(): void
    {
        $this->data['alumne_prova'] = $this->record->students()->where('es_actiu', true)->orderBy('num_taula')->value('alumnes.id');
    }

    /**
     * Variables i equips de l'alumne triat, calculats amb el formulari sense desar.
     *
     * @return array{student: Student, vars: array<string, array<string, mixed>>, hosts: list<array<string, mixed>>}|null
     */
    public function preview(): ?array
    {
        $state = $this->form->getRawState();
        $student = isset($state['alumne_prova']) ? Student::with('user')->find($state['alumne_prova']) : null;
        if ($student === null) {
            return null;
        }

        $resolver = app(VariableResolver::class);
        $grid = app(InventoryGrid::class);
        $vars = $resolver->detailed($student, $this->record);
        $values = array_map(fn (array $v) => $v['valor'], $vars);
        $common = $resolver->common($this->record);

        $hosts = [];
        $drafted = [];
        foreach ($state['hostTypes'] ?? [] as $item) {
            if (blank($item['nom'] ?? null)) {
                continue;
            }
            $type = new HostType([
                'nom' => $item['nom'],
                'grup_ansible' => $item['grup_ansible'] ?? '',
                'es_unic' => (bool) ($item['es_unic'] ?? false),
                'nom_host' => $item['nom_host'] ?? '',
                'adreca' => $item['adreca'] ?? '',
                'variables_connexio' => array_filter($item['variables_connexio'] ?? [], fn ($v, $k) => filled($k), ARRAY_FILTER_USE_BOTH),
                'secret_acces_id' => $item['secret_acces_id'] ?? null,
            ]);
            $hosts[] = ['tipus' => $type, ...$grid->host($type, $type->isUnique() ? $common : $values)];
            $drafted[] = $type->nom;
        }

        // Els globals i els del curs que el mòdul no tapa.
        foreach ($this->record->visibleHostTypes()->where('nivell', '!=', Level::Module)->whereNotIn('nom', $drafted) as $type) {
            $hosts[] = ['tipus' => $type, ...$grid->host($type, $type->isUnique() ? $common : $values)];
        }

        return ['student' => $student, 'vars' => $vars, 'hosts' => $hosts];
    }
}
