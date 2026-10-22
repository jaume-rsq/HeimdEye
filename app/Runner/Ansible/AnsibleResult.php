<?php

namespace App\Runner\Ansible;

use App\Enums\ActionStatus;

/** Sortida d'una execució d'ansible-playbook, ja interpretada. */
final readonly class AnsibleResult
{
    /**
     * @param  array<string, array<string, int>>  $stats  resum per host (ok, changed, failures, unreachable...)
     * @param  list<array<string, string>>  $events  resultat de cada tasca per host
     */
    public function __construct(
        public array $stats,
        public array $events,
        public ?int $exitCode,
        public bool $timedOut,
        public ?string $error,
        public string $rawOutput,
        public int $durationMs,
    ) {}

    /**
     * Resum per a un conjunt de hosts (els d'un alumne, més els globals).
     *
     * @param  list<string>  $hosts
     * @return array{status: ActionStatus, ok: int, changed: int, failed: int, output: string, message: ?string}
     */
    public function summarize(array $hosts): array
    {
        $ok = $changed = $failed = $unreachable = 0;
        foreach ($hosts as $host) {
            $s = $this->stats[$host] ?? [];
            $ok += $s['ok'] ?? 0;
            $changed += $s['changed'] ?? 0;
            $failed += $s['failures'] ?? 0;
            $unreachable += $s['unreachable'] ?? 0;
        }

        $events = array_values(array_filter($this->events, fn ($e) => in_array($e['host'], $hosts, true)));

        $status = match (true) {
            $this->timedOut => ActionStatus::Timeout,
            $this->error !== null || ($this->stats === [] && $this->exitCode !== 0) => ActionStatus::Error,
            $unreachable > 0 => ActionStatus::Unavailable,
            $failed > 0 => ActionStatus::Failed,
            $changed > 0 => ActionStatus::Changed,
            default => ActionStatus::Ok,
        };

        $lines = array_map(function (array $e) {
            $line = "[{$e['host']}] {$e['task']}: {$e['status']}".($e['msg'] !== '' ? " - {$e['msg']}" : '');

            return isset($e['diff']) ? $line."\n".rtrim($e['diff']) : $line;
        }, $events);

        $problem = collect($events)->first(fn ($e) => in_array($e['status'], ['failed', 'unreachable'], true));

        return [
            'status' => $status,
            'ok' => $ok,
            'changed' => $changed,
            'failed' => $failed + $unreachable,
            'output' => $lines ? implode("\n", $lines) : $this->rawOutput,
            'message' => match (true) {
                $this->timedOut => 'Ha superat el temps màxim',
                $status === ActionStatus::Error => $this->error ?? strtok(trim($this->rawOutput), "\n") ?: 'Error d\'Ansible',
                $problem !== null => mb_substr("{$problem['task']}: {$problem['msg']}", 0, 255),
                default => "ok={$ok} changed={$changed}",
            },
        ];
    }
}
