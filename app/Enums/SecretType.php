<?php

namespace App\Enums;

/**
 * Tipus de secret. Els d'usuari i contrasenya i els de clau SSH serveixen també per entrar als equips.
 */
enum SecretType: string
{
    case Value = 'valor'; // un sol valor: contrasenya, token...
    case Login = 'usuari_contrasenya'; // WinRM, API de Zabbix...
    case SshKey = 'clau_ssh'; // usuari, clau privada i clau pública

    public function label(): string
    {
        return match ($this) {
            self::Value => 'Valor',
            self::Login => 'Usuari i contrasenya',
            self::SshKey => 'Clau SSH',
        };
    }

    /** Serveix per connectar-se a un equip. */
    public function isAccess(): bool
    {
        return $this !== self::Value;
    }

    /**
     * Camps que rep el codi: HE_SECRET_<CLAU>_<CAMP> o <clau>.<camp> a Ansible. Un secret de
     * tipus valor no en té: és HE_SECRET_<CLAU> o <clau>.
     *
     * @return list<string>
     */
    public function fields(): array
    {
        return match ($this) {
            self::Value => [],
            self::Login => ['user', 'password'],
            self::SshKey => ['user', 'key_file', 'pubkey'],
        };
    }

    /** Camps que es poden fer arribar a un equip d'alumne: no donen accés a res. */
    public const PUBLIC_FIELDS = ['user', 'pubkey'];
}
