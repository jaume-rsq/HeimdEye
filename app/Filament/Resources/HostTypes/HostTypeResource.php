<?php

namespace App\Filament\Resources\HostTypes;

use App\Enums\Level;
use App\Filament\Resources\HostTypes\Pages\ManageHostTypes;
use App\Filament\Resources\HostTypes\Schemas\HostTypeFields;
use App\Filament\Resources\Secrets\Schemas\SecretFields;
use App\Models\AcademicYear;
use App\Models\HostType;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Equips comuns: tipus d'equip globals (el servidor Zabbix) o d'un curs (pve, router...), que
 * veuen tots els mòduls (o els del curs). Els d'un mòdul es gestionen a "Equips del mòdul".
 */
class HostTypeResource extends Resource
{
    protected static ?string $model = HostType::class;

    protected static ?string $slug = 'equips';

    protected static ?string $modelLabel = 'tipus d\'equip';

    protected static ?string $pluralModelLabel = 'equips comuns';

    protected static ?string $recordTitleAttribute = 'nom';

    protected static string|UnitEnum|null $navigationGroup = 'Inventari';

    protected static ?string $navigationLabel = 'Equips comuns';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('nivell', [Level::Global, Level::Course]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Select::make('nivell')
                    ->options([Level::Global->value => 'Global (tots els cursos)', Level::Course->value => 'D\'un curs'])
                    ->default(Level::Global->value)
                    ->required()
                    ->live(),
                Select::make('curs_id')
                    ->label('Curs')
                    ->relationship('academicYear', 'nom')
                    ->default(fn () => AcademicYear::where('es_actual', true)->value('id'))
                    ->visible(fn (Get $get) => $get('nivell') === Level::Course->value)
                    ->required(fn (Get $get) => $get('nivell') === Level::Course->value)
                    ->live(),
                ...array_map(
                    fn ($field) => $field->getName() === 'nom' ? $field->rules([fn (Get $get, ?HostType $record) => self::uniqueInLevel($get, $record)]) : $field,
                    HostTypeFields::make(
                        fn (Get $get) => SecretFields::commonAccessOptions($get('nivell'), $get('curs_id')),
                        'El de cada mòdul',
                    ),
                ),
            ]);
    }

    /** Un nom només pot sortir una vegada a cada nivell (global, o cada curs). */
    private static function uniqueInLevel(Get $get, ?HostType $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get, $record) {
            $exists = HostType::query()
                ->where('nivell', $get('nivell'))
                ->where('nom', $value)
                ->when($get('nivell') === Level::Course->value, fn ($q) => $q->where('curs_id', $get('curs_id')))
                ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                ->exists();
            if ($exists) {
                $fail('Ja hi ha un tipus d\'equip amb aquest nom en aquest nivell.');
            }
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('posicio')
            ->reorderable('posicio')
            ->columns([
                TextColumn::make('nom')->searchable()->sortable(),
                TextColumn::make('grup_ansible')->label('Grup Ansible')->fontFamily('mono'),
                TextColumn::make('nivell')
                    ->badge()
                    ->formatStateUsing(fn (Level $state, HostType $record) => $state === Level::Global ? 'global' : 'curs '.$record->academicYear?->nom)
                    ->color(fn (Level $state) => $state === Level::Global ? 'gray' : 'info'),
                IconColumn::make('es_unic')->label('Únic')->boolean(),
                TextColumn::make('nom_host')->label('Nom de host')->fontFamily('mono'),
                TextColumn::make('adreca')->label('Adreça')->fontFamily('mono'),
                TextColumn::make('access.clau')->label('Accés')->placeholder('el de cada mòdul'),
            ])
            ->recordActions([
                EditAction::make()->mutateDataUsing(fn (array $data) => self::normalize($data)),
                DeleteAction::make()->modalDescription('Els mòduls que el fan servir el deixaran de tenir, i el codi que l\'esmenta a "# hosts:" o al hosts: d\'un play fallarà.'),
            ]);
    }

    /** Un tipus d'equip global no té curs; cap dels comuns no té mòdul. */
    public static function normalize(array $data): array
    {
        if (($data['nivell'] ?? null) !== Level::Course->value) {
            $data['curs_id'] = null;
        }
        $data['modul_id'] = null;

        return $data;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageHostTypes::route('/'),
        ];
    }
}
