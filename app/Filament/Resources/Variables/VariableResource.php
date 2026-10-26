<?php

namespace App\Filament\Resources\Variables;

use App\Enums\VariableScope;
use App\Filament\Resources\Modules\Schemas\ModuleInventoryForm;
use App\Filament\Resources\Variables\Pages\ManageVariables;
use App\Models\AcademicYear;
use App\Models\Variable;
use App\Support\InventoryGrid;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Variables comunes: les globals i les de cada curs. Les de mòdul es gestionen a "Equips i variables".
 */
class VariableResource extends Resource
{
    protected static ?string $model = Variable::class;

    protected static ?string $slug = 'variables';

    protected static ?string $modelLabel = 'variable';

    protected static ?string $pluralModelLabel = 'variables comunes';

    protected static ?string $recordTitleAttribute = 'clau';

    protected static string|UnitEnum|null $navigationGroup = 'Inventari';

    protected static ?string $navigationLabel = 'Variables comunes';

    protected static ?int $navigationSort = 4;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedVariable;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('abast', [VariableScope::Global, VariableScope::Course]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('abast')
                    ->options([VariableScope::Global->value => 'Global (tots els cursos)', VariableScope::Course->value => 'D\'un curs'])
                    ->default(VariableScope::Global->value)
                    ->required()
                    ->live(),
                Select::make('curs_id')
                    ->label('Curs')
                    ->relationship('academicYear', 'nom')
                    ->default(fn () => AcademicYear::where('es_actual', true)->value('id'))
                    ->visible(fn (Get $get) => $get('abast') === VariableScope::Course->value)
                    ->required(fn (Get $get) => $get('abast') === VariableScope::Course->value),
                TextInput::make('clau')
                    ->label('Variable')
                    ->required()
                    ->regex(ModuleInventoryForm::KEY_RULE)
                    ->helperText('Prefixos: xarxa_, ip_, nom_, num_.')
                    ->rules([fn (Get $get, ?Variable $record) => self::uniqueInScope($get, $record)]),
                TextInput::make('valor')->label('Valor o plantilla')->required(),
                TextInput::make('descripcio')->label('Descripció')->columnSpanFull(),
            ]);
    }

    /** Una variable només pot sortir una vegada a cada àmbit (global, o cada curs). */
    private static function uniqueInScope(Get $get, ?Variable $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get, $record) {
            $exists = Variable::query()
                ->where('abast', $get('abast'))
                ->where('clau', $value)
                ->when($get('abast') === VariableScope::Course->value, fn ($q) => $q->where('curs_id', $get('curs_id')))
                ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                ->exists();
            if ($exists) {
                $fail('Ja hi ha una variable amb aquest nom en aquest àmbit.');
            }
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('clau')
            ->defaultGroup(Group::make('prefix')
                ->label('Grup')
                ->getKeyFromRecordUsing(fn (Variable $record) => array_key_first(InventoryGrid::groups([$record->clau])))
                ->getTitleFromRecordUsing(fn (Variable $record) => array_key_first(InventoryGrid::groups([$record->clau])))
                ->orderQueryUsing(fn (Builder $query) => $query)
                ->collapsible())
            ->columns([
                TextColumn::make('clau')->label('Variable')->fontFamily('mono')->searchable()->sortable(),
                TextColumn::make('valor')->label('Valor')->fontFamily('mono')->searchable(),
                TextColumn::make('abast')
                    ->label('Àmbit')
                    ->badge()
                    ->formatStateUsing(fn (VariableScope $state, Variable $record) => $state === VariableScope::Global ? 'global' : 'curs '.$record->academicYear?->nom)
                    ->color(fn (VariableScope $state) => $state === VariableScope::Global ? 'gray' : 'info'),
                TextColumn::make('descripcio')->label('Descripció')->placeholder('—')->wrap(),
            ])
            ->filters([
                SelectFilter::make('abast')->label('Àmbit')->options([VariableScope::Global->value => 'Global', VariableScope::Course->value => 'Curs']),
                SelectFilter::make('curs_id')->label('Curs')->relationship('academicYear', 'nom'),
            ])
            ->recordActions([
                EditAction::make()->mutateDataUsing(fn (array $data) => self::normalize($data)),
                DeleteAction::make(),
            ]);
    }

    /** Una variable global no té curs. */
    public static function normalize(array $data): array
    {
        if (($data['abast'] ?? null) !== VariableScope::Course->value) {
            $data['curs_id'] = null;
        }
        $data['modul_id'] = null;

        return $data;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageVariables::route('/'),
        ];
    }
}
