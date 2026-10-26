<?php

namespace App\Filament\Resources\ModuleAdmin;

use App\Enums\ModuleKind;
use App\Enums\Role;
use App\Filament\Resources\ModuleAdmin\Pages\CreateModule;
use App\Filament\Resources\ModuleAdmin\Pages\EditModule;
use App\Filament\Resources\ModuleAdmin\Pages\ListModuleAdmin;
use App\Filament\Resources\Modules\ModuleResource;
use App\Models\Block;
use App\Models\Check;
use App\Models\Module;
use App\Models\Task;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Mòduls amb els seus blocs i tasques. Els equips i les variables del mòdul es configuren a
 * Inventari → Equips i variables. No es pot esborrar res que tingui checks a dins (s'endurien
 * els resultats dels alumnes), ni un mòdul amb alumnes o accions.
 */
class ModuleAdminResource extends Resource
{
    protected static ?string $model = Module::class;

    protected static ?string $slug = 'moduls-i-blocs';

    protected static ?string $modelLabel = 'mòdul';

    protected static ?string $pluralModelLabel = 'mòduls';

    protected static ?string $recordTitleAttribute = 'nom';

    protected static string|UnitEnum|null $navigationGroup = 'Administració';

    protected static ?string $navigationLabel = 'Mòduls i blocs';

    protected static ?int $navigationSort = 4;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Mòdul')
                    ->columns(3)
                    ->schema([
                        Select::make('curs_id')->label('Curs')->relationship('academicYear', 'nom')->required(),
                        Select::make('cicle_id')->label('Cicle')->relationship('cycle', 'codi')->required(),
                        Select::make('tipus')
                            ->options([ModuleKind::Official->value => 'Oficial', ModuleKind::Free->value => 'Lliure (en combina diversos)'])
                            ->default(ModuleKind::Official->value)
                            ->required()
                            ->live(),
                        TextInput::make('codi')->placeholder('0227')->maxLength(255),
                        TextInput::make('nom')->required()->maxLength(255)->columnSpan(2),
                        TagsInput::make('combina')
                            ->label('Mòduls oficials que combina')
                            ->placeholder('0227, 0226...')
                            ->visible(fn (Get $get) => $get('tipus') === ModuleKind::Free->value)
                            ->columnSpanFull(),
                        Textarea::make('descripcio')->label('Descripció')->rows(2)->columnSpanFull(),
                        Select::make('teachers')
                            ->label('Professors')
                            ->relationship('teachers', 'email', fn ($query) => $query->whereIn('rol', [Role::Teacher->value, Role::Admin->value]))
                            ->getOptionLabelFromRecordUsing(fn (User $user) => $user->nom ?: $user->email)
                            ->multiple()
                            ->preload()
                            ->columnSpanFull(),
                    ]),
                Section::make('Blocs i tasques')
                    ->description('Els checks pengen de les tasques. Un bloc o una tasca amb checks no es pot treure.')
                    ->schema([
                        Repeater::make('blocks')
                            ->hiddenLabel()
                            ->relationship()
                            ->orderColumn('posicio')
                            ->collapsible()
                            ->itemLabel(fn (array $state) => filled($state['titol'] ?? null) ? "Bloc {$state['numero']}: {$state['titol']}" : 'Bloc nou')
                            ->addActionLabel('Afegir bloc')
                            ->deleteAction(fn (Action $action) => self::guardDelete($action, fn (Block $block) => Check::whereIn('tasca_id', $block->tasks()->select('id'))->count()))
                            ->columns(4)
                            ->schema([
                                TextInput::make('numero')->label('Número')->numeric()->minValue(0)->required()
                                    ->distinct()
                                    ->default(fn (Get $get) => collect($get('../../blocks'))->max('numero') + 1),
                                TextInput::make('titol')->label('Títol')->required()->maxLength(255)->live(onBlur: true),
                                DatePicker::make('data_inici')->label('Inici'),
                                DatePicker::make('data_fi')->label('Fi')->afterOrEqual('data_inici'),
                                Repeater::make('tasks')
                                    ->label('Tasques')
                                    ->relationship()
                                    ->orderColumn('posicio')
                                    ->collapsible()
                                    ->collapsed()
                                    ->itemLabel(fn (array $state) => $state['titol'] ?? null)
                                    ->addActionLabel('Afegir tasca')
                                    ->deleteAction(fn (Action $action) => self::guardDelete($action, fn (Task $task) => $task->checks()->count()))
                                    ->columnSpanFull()
                                    ->schema([
                                        TextInput::make('titol')->label('Títol')->required()->maxLength(255)->live(onBlur: true),
                                        Textarea::make('enunciat')->label('Enunciat (Markdown)')->rows(4),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    /**
     * Amaga el botó d'esborrar d'un element ja desat que té checks.
     *
     * @param  callable(Model): int  $checks
     */
    private static function guardDelete(Action $action, callable $checks): Action
    {
        return $action->hidden(function (array $arguments, Repeater $component) use ($checks) {
            $record = $component->getCachedExistingRecords()->get($arguments['item'] ?? '');

            return $record !== null && $checks($record) > 0;
        });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('codi')
            ->modifyQueryUsing(fn ($query) => $query->with(['academicYear', 'cycle'])->withCount(['blocks', 'tasks', 'students', 'actions']))
            ->columns([
                TextColumn::make('codi')->searchable()->sortable()->placeholder('—'),
                TextColumn::make('nom')->searchable()->sortable(),
                TextColumn::make('academicYear.nom')->label('Curs')->sortable(),
                TextColumn::make('cycle.codi')->label('Cicle'),
                TextColumn::make('tipus')->badge(),
                TextColumn::make('blocks_count')->label('Blocs'),
                TextColumn::make('tasks_count')->label('Tasques'),
                TextColumn::make('checks')->label('Checks')->state(fn (Module $record) => self::checkCount($record)),
                TextColumn::make('students_count')->label('Alumnes'),
            ])
            ->filters([
                SelectFilter::make('curs_id')->label('Curs')->relationship('academicYear', 'nom'),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('inventari')
                    ->label('Equips i variables')
                    ->icon(Heroicon::OutlinedServerStack)
                    ->url(fn (Module $record) => ModuleResource::getUrl('edit', ['record' => $record])),
                self::deleteAction(),
            ]);
    }

    public static function checkCount(Module $module): int
    {
        return Check::whereIn('tasca_id', $module->tasks()->select('tasques.id'))->count();
    }

    /** Motiu pel qual no es pot esborrar el mòdul, o null si es pot. */
    public static function deleteBlocker(Module $module): ?string
    {
        $parts = array_filter([
            ($n = self::checkCount($module)) ? "{$n} checks" : null,
            ($n = $module->students()->count()) ? "{$n} alumnes" : null,
            ($n = $module->actions()->count()) ? "{$n} accions" : null,
        ]);

        return $parts ? 'Té '.implode(', ', $parts).': no es pot esborrar.' : null;
    }

    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->disabled(fn (Module $record) => self::deleteBlocker($record) !== null)
            ->tooltip(fn (Module $record) => self::deleteBlocker($record))
            ->modalDescription('S\'esborraran els blocs, les tasques, els equips, les variables i els secrets del mòdul. No es pot desfer.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListModuleAdmin::route('/'),
            'create' => CreateModule::route('/nou'),
            'edit' => EditModule::route('/{record}'),
        ];
    }
}
