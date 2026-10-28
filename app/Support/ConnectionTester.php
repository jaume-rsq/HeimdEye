<?php

namespace App\Support;

use App\Models\HostType;
use App\Models\Module;
use App\Models\Student;
use App\Runner\Ansible\AnsibleResult;
use App\Runner\Ansible\AnsibleRunner;
use App\Runner\Ansible\InventoryBuilder;
use App\Runner\Masker;
use App\Runner\TempFiles;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Prova si els equips responen, amb el mateix inventari que fan servir accions i checks:
 * entrar per SSH (Linux i MikroTik), win_ping (Windows, WinRM) i, per a les API (httpapi), que el
 * port estigui obert.
 * No desa res. El resultat és per equip, amb la clau "alumne|tipus" ("g|tipus" per als globals).
 */
class ConnectionTester
{
    /** Segons per connectar amb cada equip. */
    public const CONNECT_TIMEOUT = 10;

    /** Equips que es proven alhora. */
    public const FORKS = 20;

    /**
     * Tasca de prova per a cada tipus de connexió. Cada tipus va en un playbook a part,
     * perquè un mòdul d'una col·lecció que no hi és (ansible.windows) fa fallar tot el playbook.
     */
    private const TASKS = [
        // Amb raw, sense Python ni directori temporal a l'equip: així també es poden provar els
        // MikroTik. Només compta entrar: l'ordre (":put ok" a RouterOS) a un Linux no existeix.
        'ssh' => <<<'YAML'
                - name: ssh
                  ansible.builtin.raw: ":put ok"
                  changed_when: false
                  failed_when: false
            YAML,
        'winrm' => <<<'YAML'
                - name: win_ping
                  ansible.windows.win_ping:
            YAML,
        'httpapi' => <<<'YAML'
                - name: port de l'API
                  ansible.builtin.wait_for:
                    host: "{{ ansible_host }}"
                    port: "{{ ansible_httpapi_port | default(443) }}"
                    timeout: 5
                  delegate_to: localhost
            YAML,
    ];

    public function __construct(
        private VariableResolver $resolver,
        private InventoryGrid $grid,
        private InventoryBuilder $builder,
        private AnsibleRunner $ansible,
    ) {}

    /** Clau d'un equip al resultat. */
    public static function key(?int $studentId, int $typeId): string
    {
        return ($studentId ?? 'g').'|'.$typeId;
    }

    /** Temps màxim de la prova, segons el nombre d'equips. */
    public static function timeout(int $hosts): int
    {
        return min(600, 30 + (int) ceil(max(1, $hosts) / self::FORKS) * (self::CONNECT_TIMEOUT + 20));
    }

    /**
     * @param  Collection<int, Student>  $students  (els tipus globals no en fan cas)
     * @param  Collection<int, HostType>  $types
     * @return array<string, array{estat: string, missatge: string}> estat: ok, no_respon, falla o error
     */
    public function test(Module $module, Collection $students, Collection $types): array
    {
        $results = [];
        $valid = collect();

        // Primer, el que no es pot ni provar: alumnes o equips amb plantilles que no es calculen.
        $globals = $this->resolver->common($module);
        foreach ($types->filter->isUnique() as $type) {
            if ($problems = $this->grid->host($type, $globals)['problemes']) {
                $results[self::key(null, $type->id)] = ['estat' => 'error', 'missatge' => implode('. ', $problems)];
            }
        }
        $studentTypes = $types->reject->isUnique();
        foreach ($studentTypes->isEmpty() ? [] : $students as $student) {
            try {
                $vars = $this->resolver->resolve($student, $module);
            } catch (InvalidArgumentException $e) {
                foreach ($studentTypes as $type) {
                    $results[self::key($student->id, $type->id)] = ['estat' => 'error', 'missatge' => $e->getMessage()];
                }

                continue;
            }
            $valid->push($student);
            foreach ($studentTypes as $type) {
                if ($problems = $this->grid->host($type, $vars)['problemes']) {
                    $results[self::key($student->id, $type->id)] = ['estat' => 'error', 'missatge' => implode('. ', $problems)];
                }
            }
        }

        $inventory = $this->builder->build($module, $valid, $types);
        // Equips a provar, per tipus de connexió: nom del host => clau del resultat.
        $hosts = [];
        $groups = [];
        foreach ($types as $type) {
            foreach (array_keys($inventory['all']['children'][$type->grup_ansible]['hosts'] ?? []) as $name) {
                $key = self::key($this->builder->hostMap()[$name] ?? null, $type->id);
                if (! isset($results[$key])) {
                    $hosts[self::kind($type)][$name] = $key;
                    $groups[self::kind($type)][] = $type->grup_ansible;
                }
            }
        }

        $files = new TempFiles;
        try {
            foreach ($hosts as $kind => $names) {
                $run = $this->ansible->run($files, $this->playbook($kind, $groups[$kind]), $inventory, [], false, self::timeout(count($names)), [
                    'ANSIBLE_FORKS' => (string) self::FORKS,
                    'ANSIBLE_TIMEOUT' => (string) self::CONNECT_TIMEOUT,
                ]);
                foreach ($names as $name => $key) {
                    $result = $this->hostResult($run, $name);
                    $results[$key] = ['estat' => $result['estat'], 'missatge' => Masker::mask($result['missatge'], $this->builder->mask())];
                }
            }
        } finally {
            $files->cleanup();
        }

        return $results;
    }

    /** Tipus de connexió d'un equip: ssh, winrm o httpapi. */
    public static function kind(HostType $type): string
    {
        return match ($type->variables_connexio['ansible_connection'] ?? 'ssh') {
            'winrm', 'psrp' => 'winrm',
            'httpapi' => 'httpapi',
            default => 'ssh',
        };
    }

    /** @param  list<string>  $groups  grups Ansible dels equips a provar */
    public function playbook(string $kind, array $groups): string
    {
        return '- hosts: '.implode(':', array_unique($groups))."\n  gather_facts: false\n  tasks:\n".self::TASKS[$kind]."\n";
    }

    /** @return array{estat: string, missatge: string} */
    private function hostResult(AnsibleResult $run, string $host): array
    {
        $stats = $run->stats[$host] ?? null;
        $event = collect($run->events)->last(fn (array $e) => $e['host'] === $host && $e['status'] !== 'skipped');
        $message = trim((string) ($event['msg'] ?? ''));

        return match (true) {
            $stats === null && $run->timedOut => ['estat' => 'error', 'missatge' => 'La prova ha superat el temps màxim'],
            $stats === null => ['estat' => 'error', 'missatge' => $run->error ?? (strtok(trim($run->rawOutput), "\n") ?: 'Ansible no ha provat aquest equip')],
            ($stats['unreachable'] ?? 0) > 0 => ['estat' => 'no_respon', 'missatge' => mb_substr($message ?: 'No respon', 0, 500)],
            ($stats['failures'] ?? 0) > 0 => ['estat' => 'falla', 'missatge' => mb_substr($message ?: 'Ha fallat', 0, 500)],
            default => ['estat' => 'ok', 'missatge' => ($event['task'] ?? '') === 'port de l\'API' ? 'El port de l\'API és obert (no comprova l\'usuari)' : 'Respon ('.($event['task'] ?? 'ssh').')'],
        };
    }
}
