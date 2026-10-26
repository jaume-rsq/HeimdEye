<?php

namespace App\Filament\Resources\Credentials;

use App\Enums\CredentialType;
use App\Filament\Resources\Credentials\Pages\ManageCredentials;
use App\Models\Credential;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Credencials (SSH, WinRM, usuari i contrasenya). El secret es desa xifrat i no es torna a mostrar.
 */
class CredentialResource extends Resource
{
    protected static ?string $model = Credential::class;

    protected static ?string $slug = 'credencials';

    protected static ?string $modelLabel = 'credencial';

    protected static ?string $pluralModelLabel = 'credencials';

    protected static ?string $recordTitleAttribute = 'nom';

    protected static string|UnitEnum|null $navigationGroup = 'Inventari';

    protected static ?string $navigationLabel = 'Credencials';

    protected static ?int $navigationSort = 5;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                TextInput::make('nom')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->helperText(fn (?Credential $record) => 'El codi la tria pel nom: "# credencial: Nom".'
                        .(($n = $record?->headerUses()) ? " Ara l'anomenen {$n} versions de codi: si la reanomenes, deixaran de trobar-la." : '')),
                Select::make('tipus')
                    ->options([
                        CredentialType::Ssh->value => 'SSH (clau privada)',
                        CredentialType::Winrm->value => 'WinRM (usuari i contrasenya)',
                        CredentialType::Password->value => 'Usuari i contrasenya (API...)',
                    ])
                    ->required()
                    ->live(),
                TextInput::make('nom_usuari')->label('Usuari')->required(),
                Textarea::make('secret')
                    ->label(fn (Get $get) => $get('tipus') === CredentialType::Ssh->value ? 'Clau privada' : 'Contrasenya')
                    ->helperText(fn (Get $get, ?Credential $record) => (filled($record?->getRawOriginal('secret')) ? 'Hi ha un secret desat: deixa-ho buit per no canviar-lo. ' : '')
                        .($get('tipus') === CredentialType::Ssh->value ? 'Sense clau, es fa servir la configurada al servidor (HEIMDEYE_SSH_KEY_PATH).' : ''))
                    ->rows(fn (Get $get) => $get('tipus') === CredentialType::Ssh->value ? 6 : 1)
                    ->extraInputAttributes(['style' => 'font-family: ui-monospace, monospace; -webkit-text-security: disc'])
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('nom')
            ->modifyQueryUsing(fn ($query) => $query->withCount(['modules', 'hostTypes']))
            ->columns([
                TextColumn::make('nom')->searchable()->sortable(),
                TextColumn::make('tipus')->badge(),
                TextColumn::make('nom_usuari')->label('Usuari'),
                IconColumn::make('secret')
                    ->label('Secret desat')
                    ->state(fn (Credential $record) => filled($record->getRawOriginal('secret')))
                    ->boolean(),
                TextColumn::make('us')
                    ->label('Es fa servir a')
                    ->state(fn (Credential $record) => self::usage($record))
                    ->placeholder('enlloc'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription(fn (Credential $record) => ($usage = self::usage($record))
                        ? "Es fa servir a: {$usage}. Els mòduls i equips que la tenen passaran a no tenir-ne cap, i el codi que l'anomena fallarà."
                        : 'No es fa servir enlloc.'),
            ]);
    }

    /** On es fa servir: mòduls, tipus d'equip i capçaleres de codi. */
    public static function usage(Credential $record): ?string
    {
        $parts = array_filter([
            ($n = $record->modules_count ?? $record->modules()->count()) ? ($n === 1 ? '1 mòdul' : "{$n} mòduls") : null,
            ($n = $record->host_types_count ?? $record->hostTypes()->count()) ? "{$n} tipus d'equip" : null,
            ($n = $record->headerUses()) ? ($n === 1 ? '1 versió de codi' : "{$n} versions de codi") : null,
        ]);

        return $parts === [] ? null : implode(', ', $parts);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCredentials::route('/'),
        ];
    }
}
