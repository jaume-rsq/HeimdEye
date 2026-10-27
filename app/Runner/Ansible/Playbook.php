<?php

namespace App\Runner\Ansible;

use App\Runner\SecretRefs;
use InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Lectura d'un playbook abans d'executar-lo: a quins grups d'equips va (el "hosts:" de cada
 * play) i si algun secret arribaria en clar a un equip d'alumne.
 */
final class Playbook
{
    /** Claus d'una tasca que en contenen d'altres. */
    private const BLOCK_KEYS = ['block', 'rescue', 'always'];

    /** Claus d'un play que contenen tasques. */
    private const TASK_LISTS = ['pre_tasks', 'tasks', 'post_tasks', 'handlers'];

    /** @param  list<array<string, mixed>>  $plays */
    private function __construct(private array $plays) {}

    /** @throws InvalidArgumentException si no és un playbook */
    public static function parse(string $code): self
    {
        try {
            $plays = Yaml::parse($code);
        } catch (ParseException $e) {
            throw new InvalidArgumentException('El playbook no és YAML vàlid: '.$e->getMessage());
        }
        if (! is_array($plays) || ! array_is_list($plays) || $plays === []) {
            throw new InvalidArgumentException('El playbook ha de ser una llista de plays');
        }
        foreach ($plays as $play) {
            if (! is_array($play)) {
                throw new InvalidArgumentException('Cada play del playbook ha de ser un diccionari');
            }
        }

        return new self($plays);
    }

    /**
     * Grups d'equips de tots els plays. Cal que cada play en digui almenys un, sense "all",
     * comodins ni variables: l'inventari només porta els equips que el codi demana.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    public function hostGroups(): array
    {
        $groups = [];
        foreach ($this->plays as $i => $play) {
            if (isset($play['import_playbook'])) {
                throw new InvalidArgumentException('No es pot fer servir import_playbook');
            }
            $pattern = $play['hosts'] ?? null;
            $pattern = is_array($pattern) ? implode(',', $pattern) : (string) $pattern;
            if (trim($pattern) === '') {
                throw new InvalidArgumentException('El play '.($i + 1).' no diu a quins equips va (hosts:)');
            }
            if (str_contains($pattern, '{{')) {
                throw new InvalidArgumentException("El hosts: «{$pattern}» no pot portar variables");
            }
            foreach (preg_split('/[:,\s]+/', $pattern, flags: PREG_SPLIT_NO_EMPTY) as $group) {
                $group = ltrim($group, '!&');
                if ($group === 'all' || preg_match('/[*?~\[]/', $group)) {
                    throw new InvalidArgumentException("El hosts: «{$pattern}» no és permès: cal dir els grups d'equips concrets");
                }
                $groups[] = $group;
            }
        }

        return array_values(array_unique($groups));
    }

    /**
     * Secrets que arribarien en clar a un equip: els que surten dins una expressió Jinja
     * sense password_hash, en una tasca que no s'executa al servidor (delegate_to: localhost).
     * L'usuari i la clau pública d'un secret (jaume.user, jaume.pubkey) no compten.
     * És una comprovació de text: en cas de dubte, compta com a fuita.
     *
     * @param  list<string>  $secrets  claus dels secrets que veu el mòdul
     * @return list<string> noms dels secrets que es filtrarien
     */
    public function leakedSecrets(array $secrets): array
    {
        $leaks = [];
        foreach ($this->plays as $play) {
            $local = self::runsLocally($play);
            foreach ($play as $key => $value) {
                if (in_array($key, self::TASK_LISTS, true)) {
                    foreach ((array) $value as $task) {
                        $leaks = [...$leaks, ...$this->taskLeaks($task, $secrets, $local)];
                    }
                } elseif (! $local) {
                    $leaks = [...$leaks, ...self::leaksIn($value, $secrets)];
                }
            }
        }

        return array_values(array_unique($leaks));
    }

    /**
     * @param  list<string>  $secrets
     * @return list<string>
     */
    private function taskLeaks(mixed $task, array $secrets, bool $local): array
    {
        if (! is_array($task)) {
            return self::leaksIn($task, $secrets);
        }

        $local = $local || self::runsLocally($task);
        $leaks = [];
        foreach ($task as $key => $value) {
            if (in_array($key, self::BLOCK_KEYS, true) && is_array($value)) {
                foreach ($value as $child) {
                    $leaks = [...$leaks, ...$this->taskLeaks($child, $secrets, $local)];
                }
            } elseif (! $local) {
                $leaks = [...$leaks, ...self::leaksIn($value, $secrets)];
            }
        }

        return $leaks;
    }

    /** @param  array<mixed>  $item */
    private static function runsLocally(array $item): bool
    {
        return isset($item['local_action'])
            || in_array($item['delegate_to'] ?? null, ['localhost', '127.0.0.1'], true)
            || ($item['connection'] ?? null) === 'local';
    }

    /**
     * @param  list<string>  $secrets
     * @return list<string>
     */
    private static function leaksIn(mixed $value, array $secrets): array
    {
        if (is_array($value)) {
            $leaks = [];
            foreach ($value as $key => $item) {
                $leaks = [...$leaks, ...self::leaksIn($key, $secrets), ...self::leaksIn($item, $secrets)];
            }

            return $leaks;
        }
        if (! is_string($value) || ! preg_match_all('/\{[{%](.*?)[}%]\}/s', $value, $m)) {
            return [];
        }

        $leaks = [];
        foreach ($m[1] as $expression) {
            if (str_contains($expression, 'password_hash')) {
                continue;
            }
            foreach ($secrets as $secret) {
                if (SecretRefs::sensitiveIn($expression, $secret)) {
                    $leaks[] = $secret;
                }
            }
        }

        return $leaks;
    }

    /**
     * El text esmenta el nom com a paraula sencera (heimdeye_ssh_pubkey_file, no
     * heimdeye_ssh_pubkey_file2) o com a variable d'entorn HE_SECRET_<NOM>.
     */
    public static function mentions(string $text, string $name): bool
    {
        $env = 'HE_SECRET_'.strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $name));

        return (bool) preg_match('/(?<![A-Za-z0-9_])(?:'.preg_quote($name, '/').'|'.preg_quote($env, '/').')(?![A-Za-z0-9_])/i', $text);
    }
}
