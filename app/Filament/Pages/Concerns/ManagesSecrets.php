<?php

namespace App\Filament\Pages\Concerns;

use App\Enums\Level;
use App\Filament\Resources\Secrets\Schemas\SecretFields;
use App\Models\Secret;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Livewire\Attributes\Computed;

/**
 * Pestanya Secrets de l'Inventari: com la de variables, però el valor no es mostra mai
 * (només si n'hi ha un de desat) i es diu on fa d'accés cada secret.
 */
trait ManagesSecrets
{
    /** @return array<string, array<string, Secret>> [secret => [nivell => registre]] */
    #[Computed]
    public function secretMatrix(): array
    {
        return $this->byLevel($this->atLevels(Secret::query())->withCount(['modules', 'hostTypes'])->get());
    }

    public function canSeeSecrets(): bool
    {
        return (bool) auth()->user()?->can('viewAny', Secret::class);
    }

    public function canEditSecrets(): bool
    {
        return $this->canSeeSecrets() && auth()->user()->can('create', Secret::class);
    }

    protected function secretAt(string $key, Level $level): ?Secret
    {
        return $this->atLevels(Secret::query())->where('nivell', $level)->where('clau', $key)->first();
    }

    public function editSecretAction(): Action
    {
        return Action::make('editSecret')
            ->authorize(fn () => $this->canEditSecrets())
            ->record(fn (array $arguments) => $this->secretAt($arguments['clau'], Level::from($arguments['nivell'])))
            ->modalHeading(fn (array $arguments) => $arguments['clau'].' · '.$this->levelLabel(Level::from($arguments['nivell'])))
            ->modalDescription(fn (array $arguments, ?Secret $record) => trim($this->reach(Level::from($arguments['nivell']))
                .(($usage = $record ? SecretFields::usage($record) : null) ? " És l'accés de: {$usage}." : '')))
            ->modalWidth('2xl')
            ->fillForm(fn (?Secret $record) => $record?->only(['tipus', 'nom_usuari', 'clau_publica', 'descripcio']) ?? [])
            ->schema(fn () => array_values(array_filter(SecretFields::make(), fn ($field) => $field->getName() !== 'clau')))
            ->action(function (array $data, array $arguments) {
                $level = Level::from($arguments['nivell']);
                $secret = $this->secretAt($arguments['clau'], $level) ?? new Secret([...$this->levelAttributes($level), 'clau' => $arguments['clau']]);
                $secret->fill($data)->save();
                unset($this->secretMatrix);
                Notification::make()->success()->title("Desat {$arguments['clau']} ({$this->levelLabel($level)})")->send();
            })
            ->extraModalFooterActions(fn (?Secret $record) => $record ? [
                Action::make('deleteSecret')
                    ->label('Esborrar d\'aquest nivell')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(($usage = SecretFields::usage($record))
                        ? "És l'accés de: {$usage}. Es quedaran sense, i el codi que l'esmenta fallarà."
                        : 'El codi que l\'esmenta fallarà, si cap altre nivell no en té un amb el mateix nom.')
                    ->action(function () use ($record) {
                        $record->delete();
                        unset($this->secretMatrix);
                        Notification::make()->success()->title("Esborrat {$record->clau}")->send();
                    })
                    ->cancelParentActions(),
            ] : []);
    }

    public function createSecretAction(): Action
    {
        return Action::make('createSecret')
            ->label('Nou secret')
            ->icon('heroicon-o-plus')
            ->authorize(fn () => $this->canEditSecrets())
            ->modalWidth('2xl')
            ->fillForm(fn () => ['nivell' => collect($this->levels())->last()->value])
            ->schema(fn () => [
                Select::make('nivell')->options(fn () => $this->levelOptions())->required()->live(),
                ...array_map(
                    fn ($field) => $field->getName() === 'clau' ? $field->rules([fn (Get $get) => $this->uniqueAtLevel(Secret::class, $get('nivell'))]) : $field,
                    SecretFields::make(),
                ),
            ])
            ->action(function (array $data) {
                $level = Level::from($data['nivell']);
                unset($data['nivell']);
                Secret::create([...$data, ...$this->levelAttributes($level)]);
                unset($this->secretMatrix);
                Notification::make()->success()->title("Creat {$data['clau']} ({$this->levelLabel($level)})")->send();
            });
    }
}
