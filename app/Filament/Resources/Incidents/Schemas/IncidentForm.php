<?php

namespace App\Filament\Resources\Incidents\Schemas;

use App\Models\Action;
use App\Models\Block;
use App\Models\Check;
use App\Models\Module;
use App\Models\Task;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;

/**
 * Definició d'una incidència i les seves opcions. Cada opció és una acció del mòdul (amb valors
 * per als seus paràmetres), el check que diu si l'alumne ja l'ha arreglada i, si cal, l'acció
 * que la desfà.
 */
class IncidentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Incidència')
                    ->columns(2)
                    ->schema([
                        TextInput::make('nom')->required()->maxLength(255),
                        Select::make('modul_id')
                            ->label('Mòdul')
                            ->options(fn () => Module::with('academicYear')->get()->mapWithKeys(fn (Module $m) => [$m->id => trim("{$m->codi} {$m->nom}")." ({$m->academicYear->nom})"]))
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set) {
                                $set('bloc_id', null);
                                $set('tasca_id', null);
                            })
                            ->disabledOn('edit'),
                        Select::make('bloc_id')
                            ->label('Bloc')
                            ->options(fn (Get $get) => Block::where('modul_id', $get('modul_id'))->orderBy('numero')->get()
                                ->mapWithKeys(fn (Block $b) => [$b->id => "Bloc {$b->numero}: {$b->titol}"]))
                            ->placeholder('Cap')
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('tasca_id', null)),
                        Select::make('tasca_id')
                            ->label('Tasca')
                            ->options(fn (Get $get) => $get('bloc_id') ? Task::where('bloc_id', $get('bloc_id'))->orderBy('posicio')->pluck('titol', 'id') : [])
                            ->placeholder('Cap (tot el bloc)')
                            ->disabled(fn (Get $get) => ! $get('bloc_id'))
                            ->helperText('Un bloc pot tenir incidències diferents per a cada tasca.'),
                        TextInput::make('equip')
                            ->label('Equip')
                            ->maxLength(255)
                            ->placeholder('p. ex. vm_dns_bind, web, aws')
                            ->helperText('Amb ssh i Ansible, limita les accions a aquest tipus d\'equip. Amb l\'agent és informatiu: mana el «# hosts:» de cada acció.'),
                        Textarea::make('simptoma')
                            ->label('Símptoma (el que llegeix l\'alumne)')
                            ->required()
                            ->rows(3)
                            ->placeholder('Els clients diuen que la web no carrega. Troba què passa i arregla-ho.')
                            ->columnSpanFull(),
                        Textarea::make('notes')
                            ->label('Notes del professor')
                            ->rows(2)
                            ->helperText('No les veu l\'alumne.')
                            ->columnSpanFull(),
                        TextInput::make('posicio')->label('Posició')->numeric()->default(0)->minValue(0),
                    ]),
                Section::make('Opcions')
                    ->description('Les avaries possibles. En llançar-la, cada alumne en rep una o més, a l\'atzar segons el pes o la que triïs.')
                    ->schema([
                        Repeater::make('options')
                            ->hiddenLabel()
                            ->relationship()
                            ->orderColumn('posicio')
                            ->columns(2)
                            ->itemLabel(fn (array $state) => $state['nom'] ?? null)
                            ->collapsible()
                            ->addActionLabel('Afegir opció')
                            ->schema([
                                TextInput::make('nom')
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder('nginx aturat')
                                    ->helperText('Només el professor el veu (fins que la revela).'),
                                TextInput::make('pes')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(100)
                                    ->default(1)
                                    ->required()
                                    ->helperText('Més pes, més probable al sorteig.'),
                                Select::make('accio_id')
                                    ->label('Acció que la injecta')
                                    ->options(fn (Get $get) => self::actions($get('../../modul_id')))
                                    ->searchable()
                                    ->required(),
                                Select::make('check_id')
                                    ->label('Check de resolució')
                                    ->options(fn (Get $get) => self::checks($get('../../modul_id')))
                                    ->searchable()
                                    ->helperText('Ha de fallar just després d\'injectar-la i passar quan l\'alumne l\'arregla.'),
                                Select::make('accio_reparacio_id')
                                    ->label('Acció de reparació')
                                    ->options(fn (Get $get) => self::actions($get('../../modul_id')))
                                    ->searchable()
                                    ->placeholder('Cap'),
                                KeyValue::make('parametres')
                                    ->label('Paràmetres de l\'acció')
                                    ->keyLabel('Nom')
                                    ->valueLabel('Valor')
                                    ->helperText('Només els que canvien respecte del valor per defecte de l\'acció. També s\'apliquen a la reparació.'),
                            ]),
                    ]),
            ]);
    }

    /** @return Collection<int, string> les d'avaria primer */
    private static function actions(mixed $moduleId): Collection
    {
        return Action::where('modul_id', $moduleId)
            ->orderByDesc('es_incidencia')
            ->orderBy('nom')
            ->get()
            ->mapWithKeys(fn (Action $a) => [$a->id => $a->nom.($a->es_incidencia ? ' (avaria)' : '')." · {$a->executor->value}"]);
    }

    /** @return Collection<int, string> */
    private static function checks(mixed $moduleId): Collection
    {
        return Check::whereHas('task.block', fn ($q) => $q->where('modul_id', $moduleId))
            ->orderBy('nom')
            ->pluck('nom', 'id');
    }
}
