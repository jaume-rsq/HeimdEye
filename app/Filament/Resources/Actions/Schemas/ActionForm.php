<?php

namespace App\Filament\Resources\Actions\Schemas;

use App\Enums\Executor;
use App\Filament\Support\CopyCode;
use App\Filament\Support\ScopeFields;
use App\Models\Action as ActionModel;
use App\Models\Block;
use App\Models\Check;
use App\Models\Task;
use App\Runner\Params;
use Filament\Actions\Action;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

/**
 * Formulari d'una acció i de la seva darrera versió. En desar, els camps de la versió
 * (VERSION_FIELDS) creen una versió nova si han canviat; les versions no es modifiquen mai.
 */
class ActionForm
{
    public const VERSION_FIELDS = ['codi', 'parametres', 'temps_max_seg', 'checks_verificacio'];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Acció')
                    ->columns(2)
                    ->schema([
                        TextInput::make('nom')->required()->maxLength(255),
                        ScopeFields::executor(Executor::Ansible),
                        Textarea::make('descripcio')->label('Descripció')->rows(2)->columnSpanFull(),
                        ScopeFields::course(['bloc_id', 'tasca_id', 'checks_verificacio']),
                        ScopeFields::module(
                            function (Set $set) {
                                $set('bloc_id', null);
                                $set('tasca_id', null);
                                $set('checks_verificacio', []);
                            },
                            fn (ActionModel $record) => self::moveWarning($record),
                        )->helperText(fn (string $operation) => $operation === 'edit'
                            ? 'Per moure l\'acció, tria un altre curs i mòdul. Les versions i l\'historial la segueixen.'
                            : null),
                        Select::make('bloc_id')
                            ->label('Bloc')
                            ->options(fn (Get $get) => Block::where('modul_id', $get('modul_id'))->orderBy('numero')->get()
                                ->mapWithKeys(fn (Block $b) => [$b->id => "Bloc {$b->numero}: {$b->titol}"]))
                            ->placeholder('Cap')
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('tasca_id', null))
                            ->helperText('La llista d\'accions s\'agrupa per bloc; les que no en tenen surten al final.'),
                        Select::make('tasca_id')
                            ->label('Tasca')
                            ->options(fn (Get $get) => $get('bloc_id') ? Task::where('bloc_id', $get('bloc_id'))->orderBy('posicio')->pluck('titol', 'id') : [])
                            ->placeholder('Cap (tot el bloc)')
                            ->disabled(fn (Get $get) => ! $get('bloc_id'))
                            ->dehydrated() // també desactivat: si es treu el bloc, es desa sense tasca
                            ->helperText('Opcional: per filtrar les accions per tasca a la llista.'),
                        TextInput::make('posicio')->label('Posició')->numeric()->default(0)->minValue(0)
                            ->helperText('Ordre dins del bloc: la seqüència en què es llancen.'),
                        TextInput::make('caducitat_agent_seg')
                            ->label('Caducitat de la feina (s)')
                            ->numeric()
                            ->minValue(10)
                            ->maxValue(86400)
                            ->placeholder('Per defecte (900)')
                            ->helperText('Segons que la feina espera que l\'agent la reculli abans de caducar. Buit = valor per defecte.')
                            ->visible(fn (Get $get) => $get('executor') === Executor::Agent->value),
                        Toggle::make('es_incidencia')
                            ->label('Acció d\'avaria (incidències)')
                            ->helperText('Trenca alguna cosa a propòsit: es fa servir des d\'Incidències i, executada sola, demana confirmació.')
                            ->columnSpanFull(),
                    ]),
                Section::make(fn (string $operation) => $operation === 'edit' ? 'Darrera versió' : 'Primera versió')
                    ->description(fn (string $operation) => $operation === 'edit'
                        ? 'Si canvies algun d\'aquests camps, en desar es crea una versió nova.'
                        : null)
                    ->schema([
                        CodeEditor::make('codi')
                            ->label('Codi')
                            ->hintAction(CopyCode::hint())
                            ->language(fn (Get $get) => $get('executor') === Executor::Ansible->value ? Language::Yaml : null)
                            ->required()
                            ->helperText('Amb ssh, cal la capçalera "# hosts: pve, vm_router" a dalt de tot; amb Ansible, el hosts: de cada play (mai "all"); amb agent, "# hosts:" és el rol (hostname) de les instàncies. Els secrets es fan servir com a $HE_SECRET_NOM (bash) o {{ nom }} (Ansible). Cap secret pot arribar en clar a un equip d\'alumne (ni per ssh ni per agent).'),
                        Repeater::make('parametres')
                            ->label('Paràmetres')
                            ->table([
                                TableColumn::make('Nom'),
                                TableColumn::make('Etiqueta'),
                                TableColumn::make('Valor per defecte'),
                            ])
                            ->schema([
                                TextInput::make('name')
                                    ->required()
                                    // Es valida sense distingir majúscules i es desa en majúscules (versionData):
                                    // el camp ja les mostra, encara que el valor no ho sigui.
                                    ->regex(Params::NAME_RULE.'i')
                                    ->validationMessages(['regex' => 'Només lletres sense accents, xifres i _ (p. ex. NOM_DNS).'])
                                    ->extraInputAttributes(['style' => 'text-transform: uppercase']),
                                TextInput::make('label'),
                                TextInput::make('default'),
                            ])
                            ->defaultItems(0)
                            ->addActionLabel('Afegir paràmetre'),
                        TextInput::make('temps_max_seg')
                            ->label('Temps màxim (s)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(3600)
                            ->helperText('Per alumne: cada alumne és una execució a part.')
                            ->default(300)
                            ->required(),
                        TextInput::make('comentari')
                            ->label('Comentari de la versió')
                            ->helperText(fn (string $operation) => $operation === 'edit'
                                ? 'Què canvia respecte de la versió anterior. Només es desa si es crea una versió nova.'
                                : null)
                            ->maxLength(1000),
                        Select::make('checks_verificacio')
                            ->label('Checks de verificació')
                            ->helperText('Es llancen després d\'executar l\'acció (no en simular).')
                            ->multiple()
                            ->options(fn (Get $get) => Check::whereHas('task.block', fn ($q) => $q->where('modul_id', $get('modul_id')))
                                ->orderBy('nom')->pluck('nom', 'id')),
                    ]),
                Section::make('Proves')
                    ->description('Resultats de provar l\'esborrany amb el botó «Provar». No es desen enlloc.')
                    ->headerActions([
                        Action::make('netejarProves')
                            ->label('Netejar')
                            ->color('gray')
                            ->size('sm')
                            ->action(fn ($livewire) => $livewire->clearTestResults()),
                    ])
                    ->schema([View::make('filament.test-results')]),
            ]);
    }

    /** Avís en moure una acció que ja s'ha executat o que fa servir alguna incidència. */
    public static function moveWarning(ActionModel $action): ?string
    {
        $runs = $action->runs()->count();
        if ($runs === 0 && ! $action->es_incidencia) {
            return null;
        }

        return "Aquesta acció ja té {$runs} execucions. Si la mous, l'historial passa al mòdul nou, el codi s'ha de poder executar amb els equips del mòdul nou i es treuen els checks de verificació (són del mòdul antic)."
            .($action->es_incidencia ? ' És una acció d\'avaria: revisa les incidències que la fan servir.' : '');
    }

    /**
     * Normalitza els camps de versió per comparar-los i desar-los.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function versionData(array $data): array
    {
        $version = array_intersect_key($data, array_flip(self::VERSION_FIELDS));

        $version['codi'] = str_replace("\r\n", "\n", (string) ($version['codi'] ?? ''));
        $version['temps_max_seg'] = (int) ($version['temps_max_seg'] ?? 300);
        $version['parametres'] = array_values(array_map(fn (array $p) => [
            'name' => Params::name($p['name'] ?? null),
            'label' => filled($p['label'] ?? null) ? $p['label'] : null,
            'default' => filled($p['default'] ?? null) ? (string) $p['default'] : null,
        ], $version['parametres'] ?? [])) ?: null;
        $ids = array_map('intval', $version['checks_verificacio'] ?? []);
        sort($ids);
        $version['checks_verificacio'] = $ids ?: null;

        return $version;
    }
}
