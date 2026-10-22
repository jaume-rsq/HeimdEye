<?php

namespace App\Runner;

use App\Enums\CheckStatus;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Executa una ordre amb timeout i converteix la sortida en un CheckResult.
 *
 * Si hi ha un embolcall configurat (p. ex. systemd-run), l'ordre s'executa a dins,
 * amb les variables d'entorn passades amb --setenv.
 */
class ProcessRunner
{
    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     * @param  callable(int): CheckStatus|null  $statusForExitCode
     */
    public function run(array $command, array $env, string $stdin, int $timeout, ?callable $statusForExitCode = null): CheckResult
    {
        $run = $this->execute($command, $env, $stdin, $timeout);

        if ($run['error'] !== null) {
            return CheckResult::error('No s\'ha pogut executar: '.$run['error']);
        }
        if ($run['timedOut']) {
            return new CheckResult(
                CheckStatus::Timeout,
                "Ha superat el temps màxim ({$timeout} s)",
                $this->limit($run['stdout']."\n".$run['stderr']),
                null,
                $run['durationMs'],
            );
        }

        $exitCode = $run['exitCode'];
        $status = $exitCode === 0
            ? CheckStatus::Pass
            : ($statusForExitCode ? $statusForExitCode($exitCode) : CheckStatus::Fail);

        return new CheckResult(
            $status,
            $this->message($run['stdout'], $run['stderr']),
            $this->limit($run['stdout']."\n".$run['stderr']),
            $exitCode,
            $run['durationMs'],
        );
    }

    /**
     * Executa l'ordre i en retorna la sortida en brut.
     *
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     * @return array{exitCode: ?int, stdout: string, stderr: string, timedOut: bool, error: ?string, durationMs: int}
     */
    public function execute(array $command, array $env, string $stdin, int $timeout): array
    {
        $process = new Process($this->wrap($command, $env, $timeout), env: $env, input: $stdin, timeout: $timeout);
        $start = hrtime(true);
        $timedOut = false;
        $error = null;

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $timedOut = true;
        } catch (RuntimeException|\ValueError $e) {
            $error = $e->getMessage();
        }

        return [
            'exitCode' => $timedOut || $error ? null : $process->getExitCode(),
            'stdout' => $error ? '' : $process->getOutput(),
            'stderr' => $error ? '' : $process->getErrorOutput(),
            'timedOut' => $timedOut,
            'error' => $error,
            'durationMs' => intdiv(hrtime(true) - $start, 1_000_000),
        ];
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     * @return list<string>
     */
    private function wrap(array $command, array $env, int $timeout): array
    {
        $wrapper = preg_split('/\s+/', trim((string) config('heimdeye.runner.wrapper')), flags: PREG_SPLIT_NO_EMPTY);
        if ($wrapper === []) {
            return $command;
        }

        if (basename($wrapper[0]) === 'systemd-run') {
            $wrapper[] = '--property=RuntimeMaxSec='.($timeout + 5);
            foreach (array_keys($env) as $name) {
                $wrapper[] = '--setenv='.$name;
            }
        }

        return [...$wrapper, ...$command];
    }

    /**
     * Missatge del check: la línia que comença per HEIMDEYE_MSG:, o si no n'hi ha,
     * la primera línia no buida de stdout (o de stderr si stdout és buida).
     */
    private function message(string $stdout, string $stderr): ?string
    {
        if (preg_match('/^HEIMDEYE_MSG:\s*(.+)$/m', $stdout, $m)) {
            return mb_substr(trim($m[1]), 0, 255);
        }

        foreach ([$stdout, $stderr] as $text) {
            foreach (preg_split('/\R/', $text) as $line) {
                if (trim($line) !== '') {
                    return mb_substr(trim($line), 0, 255);
                }
            }
        }

        return null;
    }

    /** Sortida retallada al límit configurat, o null si és buida. */
    public function limit(string $output): ?string
    {
        $output = trim($output);

        return $output === '' ? null : mb_strcut($output, 0, (int) config('heimdeye.runner.output_limit'));
    }
}
