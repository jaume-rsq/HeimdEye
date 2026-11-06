<?php

namespace App\Filament\Resources\Students\Schemas;

use App\Enums\Role;
use App\Models\AcademicYear;
use App\Models\Group;
use App\Models\Student;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Js;

class StudentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Dades')
                    ->columns(3)
                    ->schema([
                        TextInput::make('usuari')->required()->maxLength(255),
                        TextInput::make('nom_complet')->label('Nom complet')->required()->maxLength(255),
                        TextInput::make('alies')->label('Àlies')->helperText('Nom al leaderboard públic.')->maxLength(255),
                        TextInput::make('num_taula')->label('Taula')->numeric()->minValue(0)->helperText('Pot ser 0 (p. ex. la taula del professor).'),
                        TextInput::make('x')->label('X')->numeric()->minValue(0)->helperText('Índex de xarxa: 172.X / 10.X.'),
                        Toggle::make('es_actiu')->label('Actiu')->default(true)->inline(false),
                        Select::make('usuari_id')
                            ->label('Usuari (accés)')
                            ->relationship('user', 'email', fn (Builder $query) => $query->where('rol', Role::Student))
                            ->searchable()
                            ->preload()
                            ->unique(ignoreRecord: true)
                            ->helperText('Usuari amb rol alumne que entra a /alumne amb aquesta fitxa. L\'importador l\'enllaça sol.'),
                        Select::make('curs_id')
                            ->label('Curs')
                            ->relationship('academicYear', 'nom')
                            ->default(fn () => AcademicYear::where('es_actual', true)->value('id'))
                            ->required()
                            ->live(),
                        Select::make('cicle_id')->label('Cicle')->relationship('cycle', 'codi')->required(),
                        Select::make('grup_id')
                            ->label('Grup')
                            ->options(fn (Get $get) => Group::where('curs_id', $get('curs_id'))->orderBy('codi')->get()->mapWithKeys(fn (Group $g) => [$g->id => $g->label()]))
                            ->placeholder('Cap')
                            ->helperText('Grup-classe del curs (Administració → Grups).'),
                        Select::make('modules')->label('Mòduls')
                            // Només id i nom: amb moduls.* el "select distinct" falla a Postgres per la columna json.
                            ->relationship('modules', 'nom', fn (Builder $query) => $query->select('moduls.id', 'moduls.nom'))
                            ->multiple()->preload(),
                    ]),
                Section::make('Agent')
                    ->description('L\'alumne instal·la l\'agent a la seva instància amb aquesta comanda. El token el genera i el revoca el professor amb els botons de dalt; no es mostra sol.')
                    ->visible(fn (?Student $record) => $record !== null)
                    ->schema([
                        Textarea::make('comanda_agent')
                            ->label('Comanda d\'instal·lació')
                            ->dehydrated(false)
                            ->disabled()
                            ->rows(5)
                            ->helperText('Baixa el binari (segons l\'arquitectura), el fa executable i l\'instal·la com a servei. Canvia "web" pel nom que vulgui donar a cada instància (web, bd, dns...). El servei corre amb l\'usuari que llança el sudo (així hereta les credencials, p. ex. l\'AWS CLI d\'una WSL); afegeix "--user root" a l\'última línia si el vols com a root.')
                            ->formatStateUsing(fn (?Student $record) => $record?->agentInstallCommand()
                                ?? 'Encara no té token. Fes servir el botó «Generar token» de dalt.')
                            // Copia la comanda sencera (totes les línies, amb el token) al porta-retalls.
                            ->hintAction(Action::make('copiarComanda')
                                ->label('Copiar')
                                ->icon(Heroicon::OutlinedClipboardDocument)
                                ->visible(fn (?Student $record) => filled($record?->token_agent))
                                ->alpineClickHandler(fn (?Student $record) => 'window.navigator.clipboard.writeText('.Js::from((string) $record?->agentInstallCommand()).'); '
                                    .'$tooltip('.Js::from('Comanda copiada').', { theme: $store.theme, timeout: 2000 })')),
                        Textarea::make('instancies_agent')
                            ->label('Instàncies registrades')
                            ->dehydrated(false)
                            ->disabled()
                            ->rows(2)
                            ->visible(fn (?Student $record) => $record !== null && $record->agentInstances()->exists())
                            ->formatStateUsing(fn (?Student $record) => $record === null ? '' : $record->agentInstances()->orderBy('hostname')->get()
                                ->map(fn ($i) => sprintf('%s · %s · %s · batec %s', $i->hostname, $i->estat, $i->ip_publica ?? '?', $i->darrer_batec?->diffForHumans() ?? 'mai'))
                                ->implode("\n")),
                    ]),
                Section::make('Variables pròpies')
                    ->description('Tapen les globals i les derivades del mòdul amb el mateix nom. Serveixen per a les excepcions d\'un alumne. Què rep l\'alumne i d\'on surt es veu al botó Inventari.')
                    ->schema([
                        KeyValue::make('variables')
                            // Són a la taula de variables, no a les columnes de l'alumne.
                            ->formatStateUsing(fn (?Student $record) => $record?->variables ?? [])
                            ->hiddenLabel()
                            ->keyLabel('Variable')
                            ->valueLabel('Valor')
                            ->addActionLabel('Afegir variable'),
                    ]),
            ]);
    }
}
