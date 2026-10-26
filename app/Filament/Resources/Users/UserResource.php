<?php

namespace App\Filament\Resources\Users;

use App\Enums\Role;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\Module;
use App\Models\User;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Usuaris i rols. Només els administradors hi entren. Rols: administrador (tot, inclosos els
 * usuaris), professor (el panell, sense usuaris) i alumne (no entra al panell; els crea
 * l'importador). Amb Cloudflare Access es reconeix l'usuari pel correu i no cal contrasenya.
 * Ningú es pot treure a si mateix el rol d'administrador ni desactivar-se.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'usuaris';

    protected static ?string $modelLabel = 'usuari';

    protected static ?string $pluralModelLabel = 'usuaris';

    protected static ?string $recordTitleAttribute = 'email';

    protected static string|UnitEnum|null $navigationGroup = 'Administració';

    protected static ?string $navigationLabel = 'Usuaris i rols';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    public const ROLES = [
        'admin' => 'Administrador',
        'professor' => 'Professor',
        'alumne' => 'Alumne',
    ];

    public static function canAccess(): bool
    {
        return Auth::user()?->isAdmin() ?? false;
    }

    private static function isSelf(?User $record): bool
    {
        return $record !== null && $record->is(Auth::user());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('nom')->required()->maxLength(255),
                TextInput::make('email')
                    ->label('Correu')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->helperText(config('heimdeye.auth.mode') === 'cloudflare' ? 'El de Google Workspace: és com es reconeix en entrar.' : null),
                Select::make('rol')
                    ->options(self::ROLES)
                    ->default(Role::Teacher->value)
                    ->required()
                    ->live()
                    ->disabled(fn (?User $record) => self::isSelf($record))
                    ->helperText(fn (?User $record) => self::isSelf($record)
                        ? 'No et pots canviar el rol a tu mateix.'
                        : 'Administrador: tot, inclosos els usuaris. Professor: el panell, sense usuaris. Alumne: no entra al panell.'),
                Toggle::make('es_actiu')
                    ->label('Actiu')
                    ->default(true)
                    ->inline(false)
                    ->disabled(fn (?User $record) => self::isSelf($record))
                    ->helperText('Un usuari inactiu no pot entrar.'),
                TextInput::make('password')
                    ->label('Contrasenya')
                    ->password()
                    ->revealable()
                    ->required(fn (?User $record) => $record === null && config('heimdeye.auth.mode') !== 'cloudflare')
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->visible(config('heimdeye.auth.mode') !== 'cloudflare')
                    ->helperText(fn (?User $record) => $record ? 'Deixa-ho buit per no canviar-la.' : 'Només per al login local.'),
                Select::make('modules')
                    ->label('Mòduls que imparteix')
                    ->relationship('modules', 'nom')
                    ->getOptionLabelFromRecordUsing(fn (Module $m) => trim("{$m->codi} {$m->nom}"))
                    ->multiple()
                    ->preload()
                    ->visible(fn (Get $get) => ($get('rol') instanceof Role ? $get('rol')->value : $get('rol')) !== Role::Student->value),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('nom')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['modules', 'student']))
            ->columns([
                TextColumn::make('nom')->searchable()->sortable(),
                TextColumn::make('email')->label('Correu')->searchable()->sortable(),
                TextColumn::make('rol')
                    ->badge()
                    ->formatStateUsing(fn (Role $state) => self::ROLES[$state->value])
                    ->color(fn (Role $state) => match ($state) {
                        Role::Admin => 'danger',
                        Role::Teacher => 'warning',
                        Role::Student => 'gray',
                    }),
                TextColumn::make('modules.codi')->label('Mòduls')->badge()->placeholder('—'),
                TextColumn::make('student.num_taula')->label('Taula')->formatStateUsing(fn ($state) => sprintf('%02d', $state))->placeholder('—'),
                IconColumn::make('es_actiu')->label('Actiu')->boolean(),
            ])
            ->filters([
                SelectFilter::make('rol')
                    ->options(self::ROLES)
                    ->multiple()
                    ->default([Role::Admin->value, Role::Teacher->value]),
                TernaryFilter::make('es_actiu')->label('Actiu'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->disabled(fn (User $record) => self::isSelf($record) || $record->student !== null)
                    ->tooltip(fn (User $record) => match (true) {
                        self::isSelf($record) => 'No et pots esborrar a tu mateix.',
                        $record->student !== null => 'Té fitxa d\'alumne: desactiva\'l o esborra l\'alumne.',
                        default => null,
                    })
                    ->modalDescription('Les execucions i els canvis que va fer es conserven, sense autor. No es pot desfer.'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUsers::route('/'),
        ];
    }
}
