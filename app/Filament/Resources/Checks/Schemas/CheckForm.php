<?php

namespace App\Filament\Resources\Checks\Schemas;

use App\Enums\CheckKind;
use App\Enums\Executor;
use App\Models\Block;
use App\Models\Module;
use App\Runner\Params;
use Filament\Actions\Action;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

/**
 * Formulari d'un check i de la seva darrera versió, com el de les accions: en desar, els camps
 * de la versió (VERSION_FIELDS) creen una versió nova si han canviat. Una versió només corregeix
 * el codi; els punts, les dates i la resta de dades són del check i no en creen cap.
 */
class CheckForm
{
    public const VERSION_FIELDS = ['codi', 'parametres', 'temps_max_seg'];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Check')
                    ->columns(2)
                    ->schema([
                        TextInput::make('nom')->required()->maxLength(255),
                        Select::make('modul_id')
                            ->label('Mòdul')
                            ->options(fn () => Module::with('academicYear')->get()->mapWithKeys(fn (Module $m) => [$m->id => "{$m->nom} ({$m->academicYear->nom})"]))
                            ->required()
                            ->live()
                            ->dehydrated(false)
                            ->disabledOn('edit')
                            ->afterStateUpdated(fn (Set $set) => $set('tasca_id', null)),
                        Select::make('tasca_id')
                            ->label('Tasca')
                            ->options(fn (Get $get) => Block::where('modul_id', $get('modul_id'))->with('tasks')->orderBy('numero')->get()
                                ->mapWithKeys(fn (Block $b) => ["Bloc {$b->numero}: {$b->titol}" => $b->tasks->sortBy('posicio')->pluck('titol', 'id')->all()])
                                ->all())
                            ->searchable()
                            ->required()
                            ->live(),
                        Select::make('executor')
                            ->options(collect(Executor::cases())->mapWithKeys(fn (Executor $e) => [$e->value => $e->value]))
                            ->default(Executor::Ssh->value)
                            ->required()
                            ->live()
                            ->disabledOn('edit')
                            ->helperText('No es pot canviar un cop creat el check.'),
                        Textarea::make('descripcio')->label('Descripció')->rows(2)->columnSpanFull(),
                        Select::make('tipus')
                            ->options(collect(CheckKind::cases())->mapWithKeys(fn (CheckKind $k) => [$k->value => $k->value]))
                            ->default(CheckKind::Required->value)
                            ->required()
                            ->helperText('Només els obligatoris compten per al percentatge de compleció.'),
                        TextInput::make('punts')->numeric()->minValue(0)->default(1)->required(),
                        Select::make('es_actiu')
                            ->label('Actiu')
                            ->options(['1' => 'Sí', '0' => 'No'])
                            ->placeholder('Segons les dates')
                            ->formatStateUsing(fn ($state) => $state === null ? null : ($state ? '1' : '0'))
                            ->dehydrateStateUsing(fn ($state) => blank($state) ? null : (bool) $state)
                            ->helperText('Si és buit, el check és actiu entre les dates de sota (cada extrem buit és obert).'),
                        TextInput::make('posicio')->label('Posició')->numeric()->default(0)->minValue(0),
                        DatePicker::make('actiu_des')->label('Actiu des de'),
                        DatePicker::make('actiu_fins')->label('Actiu fins a'),
                        DateTimePicker::make('data_limit')->label('Data límit')->seconds(false),
                        TextInput::make('bonus_puntualitat_pct')
                            ->label('Bonus per puntualitat (%)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->default(0)
                            ->helperText('Sobre els punts, si s\'assoleix per primer cop abans de la data límit.'),
                    ]),
                Section::make(fn (string $operation) => $operation === 'edit' ? 'Darrera versió' : 'Primera versió')
                    ->description(fn (string $operation) => $operation === 'edit'
                        ? 'Si canvies algun d\'aquests camps, en desar es crea una versió nova. Una versió només ha de corregir el codi: per comprovar una cosa nova, crea un check nou.'
                        : null)
                    ->schema([
                        CodeEditor::make('codi')
                            ->label('Codi')
                            ->language(fn (Get $get) => $get('executor') === Executor::Ansible->value ? Language::Yaml : null)
                            ->required()
                            ->helperText('Amb ssh, cal la capçalera "# hosts: pve, vm_router" a dalt de tot; amb Ansible, el hosts: de cada play (mai "all"), i cap tasca pot canviar res. Amb local, capçalera opcional "# credencial: Nom". Cap secret pot arribar en clar a un equip d\'alumne.'),
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
                                    ->regex(Params::NAME_RULE)
                                    ->validationMessages(['regex' => 'Només majúscules, xifres i _ (p. ex. NOM_DNS).'])
                                    ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (?string $state, Set $set) => $set('name', Params::name($state))),
                                TextInput::make('label'),
                                TextInput::make('default')->required(),
                            ])
                            ->helperText('Un check s\'executa sol cada nit: tots els paràmetres necessiten valor per defecte (pot fer servir variables, p. ex. {{ xarxa_aula | host(3) }}).')
                            ->defaultItems(0)
                            ->addActionLabel('Afegir paràmetre'),
                        TextInput::make('temps_max_seg')
                            ->label('Temps màxim (s)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(3600)
                            ->helperText('Per alumne: cada alumne és una execució a part.')
                            ->default(15)
                            ->required(),
                        TextInput::make('comentari')
                            ->label('Comentari de la versió')
                            ->helperText(fn (string $operation) => $operation === 'edit'
                                ? 'Què es corregeix respecte de la versió anterior. Només es desa si es crea una versió nova.'
                                : null)
                            ->maxLength(1000),
                    ]),
                Section::make('Proves')
                    ->description('Resultats de provar l\'esborrany amb el botó «Provar». No es desen enlloc ni compten per als alumnes.')
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
        $version['temps_max_seg'] = (int) ($version['temps_max_seg'] ?? 15);
        $version['parametres'] = array_values(array_map(fn (array $p) => [
            'name' => Params::name($p['name'] ?? null),
            'label' => filled($p['label'] ?? null) ? $p['label'] : null,
            'default' => filled($p['default'] ?? null) ? (string) $p['default'] : null,
        ], $version['parametres'] ?? [])) ?: null;

        return $version;
    }
}
