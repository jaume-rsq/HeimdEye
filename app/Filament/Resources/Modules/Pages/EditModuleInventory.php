<?php

namespace App\Filament\Resources\Modules\Pages;

use App\Filament\Pages\InventoryOverview;
use App\Filament\Resources\Modules\Concerns\PreviewsInventory;
use App\Filament\Resources\Modules\ModuleResource;
use App\Models\Module;
use App\Support\InventoryGrid;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/**
 * Equips, variables i secrets d'un mòdul. La previsualització es calcula amb l'esborrany;
 * en desar, si alguna plantilla queda sense calcular per a algun alumne, s'avisa.
 *
 * @property Module $record
 */
class EditModuleInventory extends EditRecord
{
    use PreviewsInventory;

    protected static string $resource = ModuleResource::class;

    public function getTitle(): string
    {
        return 'Equips i variables · '.trim("{$this->record->codi} {$this->record->nom}");
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('conjunt')
                ->label('Vista de conjunt')
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

        $moduleVars = [];
        foreach ($state['variables'] ?? [] as $item) {
            if (filled($item['clau'] ?? null)) {
                $moduleVars[$item['clau']] = (string) ($item['valor'] ?? '');
            }
        }

        $resolver = app(VariableResolver::class);
        $grid = app(InventoryGrid::class);
        $vars = $resolver->detailed($student, $this->record, $moduleVars);
        $values = array_map(fn (array $v) => $v['valor'], $vars);
        $common = $resolver->common($this->record);

        $hosts = [];
        foreach ($state['hostTypes'] ?? [] as $item) {
            if (blank($item['nom'] ?? null)) {
                continue;
            }
            $type = new HostType([
                'nom' => $item['nom'],
                'grup_ansible' => $item['grup_ansible'] ?? '',
                'abast' => $item['abast'] ?? HostScope::Student->value,
                'nom_host' => $item['nom_host'] ?? '',
                'adreca' => $item['adreca'] ?? '',
                'variables_connexio' => array_filter($item['variables_connexio'] ?? [], fn ($v, $k) => filled($k), ARRAY_FILTER_USE_BOTH),
                'secret_acces_id' => $item['secret_acces_id'] ?? null,
            ]);
            $hosts[] = ['tipus' => $type, ...$grid->host($type, $type->isGlobal() ? $common : $values)];
        }

        return ['student' => $student, 'vars' => $vars, 'hosts' => $hosts];
    }

    /** Comprova les plantilles desades contra tots els alumnes actius. */
    protected function afterSave(): void
    {
        $grid = app(InventoryGrid::class)->build($this->record->fresh());
        $broken = collect($grid['rows'])->filter(fn (array $row) => $row['problemes'] > 0);
        $globals = collect($grid['globals'])->filter(fn (array $host) => $host['problemes'] !== []);
        if ($broken->isEmpty() && $globals->isEmpty()) {
            return;
        }

        $names = $broken->map(fn (array $row) => sprintf('%02d', $row['student']->num_taula))->implode(', ');
        Notification::make()
            ->warning()
            ->persistent()
            ->title('Hi ha plantilles que no es poden calcular')
            ->body(trim(($broken->isNotEmpty() ? "Alumnes afectats (taula): {$names}. " : '')
                .($globals->isNotEmpty() ? 'També equips globals: '.$globals->map(fn ($h) => $h['tipus']->nom)->implode(', ').'.' : '')))
            ->actions([
                Action::make('veure')
                    ->label('Veure a la vista de conjunt')
                    ->url(InventoryOverview::getUrl(['modul' => $this->record->id, 'problemes' => 1])),
            ])
            ->send();
    }
}
