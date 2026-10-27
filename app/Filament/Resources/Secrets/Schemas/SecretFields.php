<?php

namespace App\Filament\Resources\Secrets\Schemas;

use App\Enums\SecretType;
use App\Filament\Resources\Modules\Schemas\ModuleInventoryForm;
use App\Models\Module;
use App\Models\Secret;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;

/**
 * Camps d'un secret, comuns a Secrets comuns i a la pestanya Secrets del mòdul. El valor
 * (contrasenya o clau privada) es desa xifrat i no es torna a mostrar.
 */
class SecretFields
{
    /** @return list<Component|Field> */
    public static function make(): array
    {
        return [
            TextInput::make('clau')
                ->label('Nom')
                ->required()
                ->regex(ModuleInventoryForm::KEY_RULE)
                ->helperText(fn (?Secret $record) => 'Minúscules: jaume, ssh_jaume, api_zabbix.'
                    .(($n = $record?->headerUses()) ? " L'anomenen {$n} capçaleres \"# credencial:\": si el reanomenes, deixaran de trobar-lo." : '')),
            Select::make('tipus')
                ->options(collect(SecretType::cases())->mapWithKeys(fn (SecretType $t) => [$t->value => $t->label()]))
                ->default(SecretType::Value->value)
                ->required()
                ->live(),
            TextInput::make('nom_usuari')
                ->label('Usuari')
                ->visible(fn (Get $get) => self::type($get)->isAccess())
                ->required(fn (Get $get) => self::type($get)->isAccess()),
            Textarea::make('valor')
                ->label(fn (Get $get) => match (self::type($get)) {
                    SecretType::SshKey => 'Clau privada',
                    SecretType::Login => 'Contrasenya',
                    SecretType::Value => 'Valor',
                })
                ->helperText(fn (Get $get, ?Secret $record) => ($record?->hasValue() ? 'Hi ha un valor desat: deixa-ho buit per no canviar-lo. ' : '')
                    .(self::type($get) === SecretType::SshKey ? 'Sense clau, es fa servir la configurada al servidor (HEIMDEYE_SSH_KEY_PATH).' : ''))
                ->rows(fn (Get $get) => self::type($get) === SecretType::SshKey ? 6 : 1)
                ->extraInputAttributes(['style' => 'font-family: ui-monospace, monospace; -webkit-text-security: disc'])
                ->dehydrated(fn (?string $state) => filled($state))
                // En la consulta no es mostra el valor (visitants de la demo).
                ->hiddenOn('view')
                ->columnSpanFull(),
            Textarea::make('clau_publica')
                ->label('Clau pública')
                ->helperText('No és secreta: el codi la pot instal·lar als equips (HE_SECRET_<NOM>_PUBKEY o <nom>.pubkey).')
                ->rows(2)
                ->extraInputAttributes(['style' => 'font-family: ui-monospace, monospace'])
                ->visible(fn (Get $get) => self::type($get) === SecretType::SshKey)
                ->columnSpanFull(),
            TextInput::make('descripcio')->label('Descripció')->columnSpanFull(),
        ];
    }

    public static function help(): View
    {
        return View::make('filament.secret-help');
    }

    /**
     * Secrets que poden fer d'accés als equips d'un mòdul.
     *
     * @return array<int, string>
     */
    public static function accessOptions(?Module $module): array
    {
        if ($module === null) {
            return [];
        }

        return $module->visibleSecrets()
            ->filter(fn (Secret $secret) => $secret->tipus->isAccess())
            ->sortKeys()
            ->mapWithKeys(fn (Secret $secret) => [$secret->id => "{$secret->clau} ({$secret->tipus->label()}, {$secret->nivell->value})"])
            ->all();
    }

    private static function type(Get $get): SecretType
    {
        $state = $get('tipus');

        return $state instanceof SecretType ? $state : (SecretType::tryFrom((string) $state) ?? SecretType::Value);
    }
}
