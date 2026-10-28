<?php

namespace App\Filament\Pages\Concerns;

use App\Enums\Level;
use App\Filament\Resources\HostTypes\Schemas\HostTypeFields;
use App\Filament\Resources\Secrets\Schemas\SecretFields;
use App\Models\HostType;
use App\Models\Student;
use App\Support\InventoryGrid;
use App\Support\VariableResolver;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Livewire\Attributes\Computed;

/**
 * Pestanya Equips de l'Inventari: els tipus d'equip que veu el mòdul (globals, del curs i del
 * mòdul), un per fila amb el que en defineix cada nivell. Un del mòdul tapa un de comú amb el
 * mateix nom. També l'accés per defecte del mòdul.
 */
trait ManagesHostTypes
{
    /** @return array<string, array<string, HostType>> [nom => [nivell => tipus]] */
    #[Computed]
    public function hostTypeMatrix(): array
    {
        $types = $this->atLevels(HostType::query())->with('access')->orderBy('posicio')->orderBy('id')->get();

        return $this->byLevel($types, 'nom', sort: false);
    }

    public function canEditHostTypes(): bool
    {
        return (bool) auth()->user()?->can('create', HostType::class);
    }

    protected function hostTypeAt(string $name, Level $level): ?HostType
    {
        return $this->atLevels(HostType::query())->where('nivell', $level)->where('nom', $name)->first();
    }

    /** Secrets que poden fer d'accés a un tipus d'equip d'aquest nivell. */
    protected function accessOptionsAt(Level $level): array
    {
        return $level === Level::Module
            ? SecretFields::accessOptions($this->module)
            : SecretFields::commonAccessOptions($level->value, $this->course?->id);
    }

    protected function hostTypeFields(Level $level, bool $withName): array
    {
        $fields = HostTypeFields::make(
            fn () => $this->accessOptionsAt($level),
            $level === Level::Module ? 'El del mòdul' : 'El de cada mòdul',
        );
        if (! $withName) {
            $fields = array_values(array_filter($fields, fn ($field) => $field->getName() !== 'nom'));
        }

        return [...$fields, ...$this->hostPreviewFields()];
    }

    public function editHostTypeAction(): Action
    {
        return Action::make('editHostType')
            ->authorize(fn () => $this->canEditHostTypes())
            ->record(fn (array $arguments) => $this->hostTypeAt($arguments['nom'], Level::from($arguments['nivell'])))
            ->modalHeading(fn (array $arguments) => $arguments['nom'].' · '.$this->levelLabel(Level::from($arguments['nivell'])))
            ->modalDescription(fn (array $arguments) => $this->reach(Level::from($arguments['nivell'])))
            ->modalWidth('4xl')
            ->fillForm(fn (?HostType $record, array $arguments) => [
                ...($record?->only(['grup_ansible', 'es_unic', 'nom_host', 'adreca', 'secret_acces_id', 'variables_connexio'])
                    ?? $this->hostTypeDefaults($arguments['nom'])),
                'alumne_prova' => $this->previewStudents()->keys()->first(),
            ])
            ->schema(fn (array $arguments) => $this->hostTypeFields(Level::from($arguments['nivell']), withName: false))
            ->action(function (array $data, array $arguments) {
                $level = Level::from($arguments['nivell']);
                $type = $this->hostTypeAt($arguments['nom'], $level)
                    ?? new HostType([...$this->levelAttributes($level), 'nom' => $arguments['nom'], 'posicio' => $this->nextPosition()]);
                $type->fill($this->hostTypeData($data))->save();
                $this->afterHostTypeChange($level, "Desat {$arguments['nom']} ({$this->levelLabel($level)})");
            })
            ->extraModalFooterActions(fn (?HostType $record) => $record ? [
                Action::make('deleteHostType')
                    ->label('Esborrar d\'aquest nivell')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Si un altre nivell en té un amb el mateix nom, passa a manar aquell; si no, el codi que esmenta el grup a "# hosts:" o al hosts: d\'un play fallarà.')
                    ->action(function () use ($record) {
                        $record->delete();
                        $this->afterHostTypeChange($record->nivell, "Esborrat {$record->nom}");
                    })
                    ->cancelParentActions(),
            ] : []);
    }

    public function createHostTypeAction(): Action
    {
        return Action::make('createHostType')
            ->label('Nou tipus d\'equip')
            ->icon('heroicon-o-plus')
            ->authorize(fn () => $this->canEditHostTypes())
            ->modalWidth('4xl')
            ->fillForm(fn () => ['nivell' => collect($this->levels())->last()->value, 'alumne_prova' => $this->previewStudents()->keys()->first()])
            ->schema(fn () => [
                Select::make('nivell')->options(fn () => $this->levelOptions())->required()->live(),
                ...array_map(
                    fn ($field) => $field->getName() === 'nom' ? $field->rules([fn (Get $get) => $this->uniqueAtLevel(HostType::class, $get('nivell'), 'nom')]) : $field,
                    HostTypeFields::make(fn (Get $get) => $this->accessOptionsAt(Level::tryFrom((string) $get('nivell')) ?? Level::Module)),
                ),
                ...$this->hostPreviewFields(),
            ])
            ->action(function (array $data) {
                $level = Level::from($data['nivell']);
                HostType::create([...$this->hostTypeData($data), ...$this->levelAttributes($level), 'nom' => $data['nom'], 'posicio' => $this->nextPosition()]);
                $this->afterHostTypeChange($level, "Creat {$data['nom']} ({$this->levelLabel($level)})");
            });
    }

    public function moduleAccessAction(): Action
    {
        return Action::make('moduleAccess')
            ->label(fn () => 'Accés per defecte: '.($this->module?->access?->clau ?? 'cap'))
            ->color('gray')
            ->icon('heroicon-o-key')
            ->visible(fn () => $this->module !== null)
            ->authorize(fn () => (bool) auth()->user()?->can('update', $this->module))
            ->modalHeading('Accés per defecte del mòdul')
            ->modalDescription('Un secret d\'usuari i contrasenya o de clau SSH. El fan servir els equips que no en tenen cap, i els scripts locals.')
            ->fillForm(fn () => ['secret_acces_id' => $this->module?->secret_acces_id])
            ->schema([
                Select::make('secret_acces_id')->label('Accés')->options(fn () => SecretFields::accessOptions($this->module)),
            ])
            ->action(function (array $data) {
                $this->module->update(['secret_acces_id' => $data['secret_acces_id'] ?: null]);
                unset($this->module, $this->grid);
                Notification::make()->success()->title('Desat l\'accés per defecte')->send();
            });
    }

    /** Un tipus d'equip nou en un altre nivell parteix del que ja hi ha amb aquest nom. */
    protected function hostTypeDefaults(string $name): array
    {
        $cells = $this->hostTypeMatrix[$name] ?? [];
        $base = $cells[$this->winner($cells)] ?? null;

        return $base?->only(['grup_ansible', 'es_unic', 'nom_host', 'adreca', 'variables_connexio']) ?? [];
    }

    protected function hostTypeData(array $data): array
    {
        $data = array_intersect_key($data, array_flip(['grup_ansible', 'es_unic', 'nom_host', 'adreca', 'secret_acces_id', 'variables_connexio']));
        $data['variables_connexio'] = array_filter($data['variables_connexio'] ?? [], fn ($v, $k) => filled($k), ARRAY_FILTER_USE_BOTH) ?: null;
        $data['secret_acces_id'] = ($data['secret_acces_id'] ?? null) ?: null;

        return $data;
    }

    protected function nextPosition(): int
    {
        return (int) $this->atLevels(HostType::query())->max('posicio') + 1;
    }

    protected function afterHostTypeChange(Level $level, string $message): void
    {
        unset($this->hostTypeMatrix, $this->grid);
        Notification::make()->success()->title($message)->send();
        $this->warnBrokenTemplates($level);
    }

    /** Nom i adreça de l'equip per a un alumne, calculats amb el formulari sense desar. */
    protected function hostPreviewFields(): array
    {
        if ($this->module === null) {
            return [];
        }

        return [
            Select::make('alumne_prova')
                ->label('Previsualitzar per a')
                ->options(fn () => $this->previewStudents()->all())
                ->dehydrated(false)
                ->live(),
            TextEntry::make('resultat')
                ->label('Resultat')
                ->state(fn (Get $get) => $this->previewHost([
                    'es_unic' => (bool) $get('es_unic'),
                    'nom_host' => (string) $get('nom_host'),
                    'adreca' => (string) $get('adreca'),
                    'variables_connexio' => $get('variables_connexio'),
                ], $get('alumne_prova')))
                ->fontFamily('mono'),
        ];
    }

    /** Com queda un tipus d'equip (esborrany) per a un alumne: "nom → adreça" o el problema. */
    public function previewHost(array $draft, mixed $studentId): string
    {
        $student = $this->module ? Student::with('user')->find($studentId) : null;
        if ($student === null) {
            return '—';
        }
        $type = new HostType([
            'es_unic' => $draft['es_unic'] ?? false,
            'nom_host' => $draft['nom_host'] ?? '',
            'adreca' => $draft['adreca'] ?? '',
            'variables_connexio' => array_filter((array) ($draft['variables_connexio'] ?? []), fn ($v, $k) => filled($k), ARRAY_FILTER_USE_BOTH),
        ]);
        $resolver = app(VariableResolver::class);
        $vars = $type->isUnique()
            ? $resolver->common($this->module)
            : array_map(fn (array $v) => $v['valor'], $resolver->detailed($student, $this->module));
        $host = app(InventoryGrid::class)->host($type, $vars);

        return $host['problemes'] !== [] ? implode('; ', $host['problemes']) : "{$host['nom']} → {$host['adreca']}";
    }
}
