<?php

namespace App\Filament\Resources\Secrets;

use App\Enums\Level;
use App\Enums\SecretType;
use App\Filament\Resources\Secrets\Pages\ManageSecrets;
use App\Filament\Resources\Secrets\Schemas\SecretFields;
use App\Models\AcademicYear;
use App\Models\Secret;
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
 * Secrets comuns: els globals i els de cada curs (claus SSH, usuaris i contrasenyes, tokens).
 * Els d'un mòdul es gestionen a "Equips i variables".
 */
class SecretResource extends Resource
{
    protected static ?string $model = Secret::class;

    protected static ?string $slug = 'secrets';

    protected static ?string $modelLabel = 'secret';

    protected static ?string $pluralModelLabel = 'secrets comuns';

    protected static ?string $recordTitleAttribute = 'clau';

    protected static string|UnitEnum|null $navigationGroup = 'Inventari';

    protected static ?string $navigationLabel = 'Secrets comuns';

    protected static ?int $navigationSort = 5;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('nivell', [Level::Global, Level::Course]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
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
                    ->required(fn (Get $get) => $get('nivell') === Level::Course->value),
                ...array_map(
                    fn ($field) => $field->getName() === 'clau' ? $field->rules([fn (Get $get, ?Secret $record) => self::uniqueInLevel($get, $record)]) : $field,
                    SecretFields::make(),
                ),
                SecretFields::help()->columnSpanFull(),
            ]);
    }

    /** Un secret només pot sortir una vegada a cada nivell (global, o cada curs). */
    private static function uniqueInLevel(Get $get, ?Secret $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get, $record) {
            $exists = Secret::query()
                ->where('nivell', $get('nivell'))
                ->where('clau', $value)
                ->when($get('nivell') === Level::Course->value, fn ($q) => $q->where('curs_id', $get('curs_id')))
                ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                ->exists();
            if ($exists) {
                $fail('Ja hi ha un secret amb aquest nom en aquest nivell.');
            }
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('clau')
            ->modifyQueryUsing(fn ($query) => $query->withCount(['modules', 'hostTypes']))
            ->columns([
                TextColumn::make('clau')->label('Nom')->fontFamily('mono')->searchable()->sortable(),
                TextColumn::make('tipus')->badge()->formatStateUsing(fn (SecretType $state) => $state->label()),
                TextColumn::make('nivell')
                    ->label('Nivell')
                    ->badge()
                    ->formatStateUsing(fn (Level $state, Secret $record) => $state === Level::Global ? 'global' : 'curs '.$record->academicYear?->nom)
                    ->color(fn (Level $state) => $state === Level::Global ? 'gray' : 'info'),
                TextColumn::make('nom_usuari')->label('Usuari')->placeholder('—'),
                IconColumn::make('valor')
                    ->label('Valor desat')
                    ->state(fn (Secret $record) => $record->hasValue())
                    ->boolean(),
                TextColumn::make('us')
                    ->label('Accés de')
                    ->state(fn (Secret $record) => self::usage($record))
                    ->placeholder('—'),
                TextColumn::make('descripcio')->label('Descripció')->placeholder('—')->wrap()->toggleable(),
            ])
            ->recordActions([
                EditAction::make()->mutateDataUsing(fn (array $data) => self::normalize($data)),
                DeleteAction::make()
                    ->modalDescription(fn (Secret $record) => ($usage = self::usage($record))
                        ? "És l'accés de: {$usage}. Es quedaran sense, i el codi que l'esmenta fallarà."
                        : 'El codi que l\'esmenta fallarà.'),
            ]);
    }

    /** On fa d'accés: mòduls, tipus d'equip i capçaleres de codi. */
    public static function usage(Secret $record): ?string
    {
        $parts = array_filter([
            ($n = $record->modules_count ?? $record->modules()->count()) ? ($n === 1 ? '1 mòdul' : "{$n} mòduls") : null,
            ($n = $record->host_types_count ?? $record->hostTypes()->count()) ? "{$n} tipus d'equip" : null,
            ($n = $record->headerUses()) ? ($n === 1 ? '1 capçalera de codi' : "{$n} capçaleres de codi") : null,
        ]);

        return $parts === [] ? null : implode(', ', $parts);
    }

    /** Un secret global no té curs; cap dels comuns no té mòdul. */
    public static function normalize(array $data): array
    {
        if (($data['nivell'] ?? null) !== Level::Course->value) {
            $data['curs_id'] = null;
        }
        $data['modul_id'] = null;
        if (($data['tipus'] ?? null) !== SecretType::SshKey->value) {
            $data['clau_publica'] = null;
        }

        return $data;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSecrets::route('/'),
        ];
    }
}
