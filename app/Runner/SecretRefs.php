<?php

namespace App\Runner;

use App\Enums\SecretType;
use App\Models\Secret;

/**
 * Com esmenta el codi un secret i com el rep.
 *
 * - bash (executors local i ssh): HE_SECRET_<CLAU>, o HE_SECRET_<CLAU>_<CAMP> si el secret té
 *   camps (USER, PASSWORD, KEY_FILE, PUBKEY). El nom sol no compta: "jaume" pot ser un usuari.
 *   Només es dona l'entorn que el codi fa servir: amb ssh, HE_SECRET_X_PUBKEY no arrossega la contrasenya.
 * - Ansible: <clau> o <clau>.<camp> dins d'una expressió Jinja, o també la variable d'entorn.
 *
 * Només es dona al codi els secrets que esmenta, i mai a un equip d'alumne els camps que
 * donen accés (valor, password, key_file).
 */
final class SecretRefs
{
    public static function envName(string $key, ?string $field = null): string
    {
        return 'HE_SECRET_'.strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $key.($field === null ? '' : "_{$field}")));
    }

    /**
     * Variables d'entorn del secret que surten al codi.
     *
     * @return list<string>
     */
    public static function envNamesIn(string $code, Secret $secret): array
    {
        return array_values(array_filter(
            self::envNames($secret),
            fn (string $name) => (bool) preg_match('/(?<![A-Za-z0-9_])'.preg_quote($name, '/').'(?![A-Za-z0-9_])/', $code),
        ));
    }

    /** El codi bash fa servir alguna variable d'entorn del secret. */
    public static function inBash(string $code, Secret $secret): bool
    {
        return self::envNamesIn($code, $secret) !== [];
    }

    /**
     * Variables d'entorn del secret que donen accés (totes menys USER i PUBKEY) i que surten al codi.
     *
     * @return list<string>
     */
    public static function sensitiveEnvNamesIn(string $code, Secret $secret): array
    {
        $public = array_map(fn ($field) => self::envName($secret->clau, $field), SecretType::PUBLIC_FIELDS);

        return array_values(array_diff(self::envNamesIn($code, $secret), $public));
    }

    /** @return list<string> */
    public static function envNames(Secret $secret): array
    {
        $fields = $secret->tipus->fields();

        return $fields === []
            ? [self::envName($secret->clau)]
            : array_map(fn ($field) => self::envName($secret->clau, $field), $fields);
    }

    /**
     * El codi Ansible fa servir el secret: pel nom dins d'una expressió Jinja ({{ jaume.password }},
     * o un when:), o per la variable d'entorn. Fora d'una expressió, "jaume" és només un text.
     */
    public static function inAnsible(string $code, Secret $secret): bool
    {
        if (self::inBash($code, $secret)) {
            return true;
        }
        preg_match_all('/\{[{%](.*?)[}%]\}/s', $code, $jinja);
        preg_match_all('/^\s*-?\s*(?:when|that|failed_when|changed_when|until)\s*:(.*)$/m', $code, $conditions);
        $name = '/(?<![A-Za-z0-9_.])'.preg_quote($secret->clau, '/').'(?![A-Za-z0-9_])/';
        foreach ([...$jinja[1], ...$conditions[1]] as $expression) {
            if ($expression !== '' && preg_match($name, $expression)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Una expressió Jinja fa servir una part del secret que dona accés: el secret sencer,
     * el valor, la contrasenya o la clau privada (no l'usuari ni la clau pública).
     */
    public static function sensitiveIn(string $expression, string $key): bool
    {
        $public = implode('|', array_map(fn ($f) => preg_quote($f, '/'), SecretType::PUBLIC_FIELDS));
        $byName = '(?<![A-Za-z0-9_.])'.preg_quote($key, '/').'(?![A-Za-z0-9_])(?!\s*(?:\.\s*(?:'.$public.')\b|\[\s*[\'"](?:'.$public.')[\'"]\s*\]))';
        $byEnv = '(?<![A-Za-z0-9_])'.preg_quote(self::envName($key), '/').'(?:_(?:PASSWORD|KEY_FILE))?(?![A-Za-z0-9_])';

        return (bool) preg_match('/'.$byName.'|'.$byEnv.'/', $expression);
    }

    /**
     * Variables d'entorn d'un secret (el fitxer de la clau privada ja escrit).
     *
     * @return array<string, string>
     */
    public static function env(Secret $secret, ?string $keyFile = null): array
    {
        return match ($secret->tipus) {
            SecretType::Value => [self::envName($secret->clau) => (string) $secret->valor],
            SecretType::Login => [
                self::envName($secret->clau, 'user') => (string) $secret->nom_usuari,
                self::envName($secret->clau, 'password') => (string) $secret->valor,
            ],
            SecretType::SshKey => array_filter([
                self::envName($secret->clau, 'user') => (string) $secret->nom_usuari,
                self::envName($secret->clau, 'key_file') => (string) $keyFile,
                self::envName($secret->clau, 'pubkey') => trim((string) $secret->clau_publica),
            ], fn ($value) => $value !== ''),
        };
    }

    /**
     * Fitxer amb la clau privada d'un secret de clau SSH: la ruta, si el valor és una ruta
     * absoluta d'un fitxer del servidor, o un fitxer temporal amb la clau. Null si no en té.
     */
    public static function keyFile(Secret $secret, TempFiles $files): ?string
    {
        if (! $secret->hasValue()) {
            return null;
        }
        $key = trim((string) $secret->valor);

        return self::isPath($key) ? $key : $files->write("key-{$secret->id}", $key."\n");
    }

    /** Fitxer temporal amb la clau pública d'un secret de clau SSH. Null si no en té. */
    public static function pubkeyFile(Secret $secret, TempFiles $files): ?string
    {
        $key = trim((string) $secret->clau_publica);

        return $key === '' ? null : $files->write("pubkey-{$secret->id}", $key."\n");
    }

    /** Una sola línia que comença per /: la ruta d'un fitxer de clau, no la clau. */
    public static function isPath(string $value): bool
    {
        return str_starts_with($value, '/') && ! str_contains($value, "\n");
    }

    /** Valor de la variable Ansible del secret: el valor o un diccionari amb els camps. */
    public static function ansibleValue(Secret $secret, ?string $keyFile = null): mixed
    {
        return match ($secret->tipus) {
            SecretType::Value => (string) $secret->valor,
            SecretType::Login => ['user' => (string) $secret->nom_usuari, 'password' => (string) $secret->valor],
            SecretType::SshKey => [
                'user' => (string) $secret->nom_usuari,
                'key_file' => (string) $keyFile,
                'pubkey' => trim((string) $secret->clau_publica),
            ],
        };
    }
}
