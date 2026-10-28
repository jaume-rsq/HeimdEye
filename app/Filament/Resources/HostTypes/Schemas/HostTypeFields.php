<?php

namespace App\Filament\Resources\HostTypes\Schemas;

use App\Models\Variable;
use Closure;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

/**
 * Camps d'un tipus d'equip, de la pestanya Equips de l'Inventari.
 */
class HostTypeFields
{
    /**
     * @param  Closure  $accessOptions  secrets que poden fer d'accés: [id => etiqueta]
     * @return list<Field>
     */
    public static function make(Closure $accessOptions, string $defaultAccess = 'El del mòdul'): array
    {
        return [
            TextInput::make('nom')->required()->live(onBlur: true)->helperText('pve, router, dns...'),
            TextInput::make('grup_ansible')->label('Grup Ansible')->required()->regex(Variable::KEY_RULE),
            Toggle::make('es_unic')
                ->label('Equip únic')
                ->helperText('Un sol equip per a tothom (p. ex. el servidor Zabbix). Si no, un per alumne.')
                ->inline(false)
                ->live(),
            TextInput::make('nom_host')->label('Nom de host')->required()->live(onBlur: true)->helperText('Plantilla: pve{{num_taula}}-{{usuari}}'),
            TextInput::make('adreca')->label('Adreça')->required()->live(onBlur: true)->helperText('Plantilla: {{ip_pve}}'),
            Select::make('secret_acces_id')
                ->label('Accés')
                ->options($accessOptions)
                ->placeholder($defaultAccess),
            KeyValue::make('variables_connexio')
                ->label('Variables de l\'equip')
                ->helperText('Variables Ansible d\'aquest equip, com un group_vars: ansible_connection, ansible_port, ansible_user... Poden ser plantilles.')
                ->keyLabel('Variable')
                ->valueLabel('Valor')
                ->columnSpanFull(),
        ];
    }
}
