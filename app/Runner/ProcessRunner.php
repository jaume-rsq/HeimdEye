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
        $process = new Process($this->wrap($command, $env, $timeout), env: $env, input: $stdin, timeout: $timeout);
        $start = hrtime(true);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return new CheckResult(
                CheckStatus::Timeout,
                "Ha superat el temps màxim ({$timeout} s)",
                $this->output($process),
                null,
                $this->elapsed($start),
            );
        } catch (RuntimeException $e) {
            return CheckResult::error('No s\'ha pogut executar: '.$e->getMessage());
        }

        $exitCode = $process->getExitCode();
        $status = $exitCode === 0
            ? CheckStatus::Pass
            : ($statusForExitCode ? $statusForExitCode($exitCode) : CheckStatus::Fail);

        return new CheckResult(
            $status,
            $this->message($process->getOutput(), $process->getErrorOutput()),
            $this->output($process),
            $exitCode,
            $this->elapsed($start),
        );
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

    private function output(Process $process): ?string
    {
        $output = trim($process->getOutput()."\n".$process->getErrorOutput());

        return $output === '' ? null : mb_strcut($output, 0, (int) config('heimdeye.runner.output_limit'));
    }

    private function elapsed(int $start): int
    {
        return intdiv(hrtime(true) - $start, 1_000_000);
    }
}
