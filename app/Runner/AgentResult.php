<?php

namespace App\Runner;

use App\Enums\ActionStatus;
use App\Enums\CheckStatus;

/**
 * Com s'interpreta el que torna l'agent (codi de sortida, sortida i si ha superat el temps), amb els
 * mateixos criteris que una execució local. El fan servir les accions, els checks i les proves de
 * l'editor.
 */
final class AgentResult
{
    /** Temps màxim → Timeout; línia HEIMDEYE_OMES → Skipped; exit 0 → Pass; si no → Fail. */
    public static function check(int $exit, string $output, bool $timedOut, int $durationMs = 0): CheckResult
    {
        $output = trim($output);
        if ($timedOut) {
            return new CheckResult(CheckStatus::Timeout, 'Ha superat el temps màxim', self::limit($output), null, $durationMs);
        }

        $status = $exit === 0 ? CheckStatus::Pass : CheckStatus::Fail;

        return (new CheckResult($status, self::message($output), self::limit($output), $exit, $durationMs))->skippedIfAsked();
    }

    /** Una línia HEIMDEYE_OMES fa que l'acció sigui "no disponible"; si no, el temps o l'exit code manen. */
    public static function action(int $exit, string $output, bool $timedOut): ActionStatus
    {
        if ($timedOut) {
            return ActionStatus::Timeout;
        }
        if (preg_match('/^HEIMDEYE_OMES:/m', $output)) {
            return ActionStatus::Unavailable;
        }

        return $exit === 0 ? ActionStatus::Ok : ActionStatus::Failed;
    }

    /** Missatge de la feina: la línia HEIMDEYE_MSG:, o si no n'hi ha, la primera línia no buida. */
    public static function message(string $output): ?string
    {
        if (preg_match('/^HEIMDEYE_MSG:\s*(.+)$/m', $output, $m)) {
            return mb_substr(trim($m[1]), 0, 255);
        }
        foreach (preg_split('/\R/', $output) as $line) {
            if (trim($line) !== '') {
                return mb_substr(trim($line), 0, 255);
            }
        }

        return null;
    }

    /** Si la feina ha fallat, hi afegeix el codi de sortida al final (o és tota la sortida si no n'hi havia). */
    public static function withExit(string $output, int $exit, bool $timedOut): string
    {
        if ($exit === 0 || $timedOut) {
            return $output;
        }
        $line = "[codi de sortida {$exit}]";

        return trim($output) === '' ? "Sense sortida {$line}" : rtrim($output)."\n{$line}";
    }

    /** @param  list<string>  $mask */
    public static function maskedOutput(string $output, array $mask): ?string
    {
        return self::limit(Masker::mask(trim($output), $mask) ?? '');
    }

    public static function limit(string $output): ?string
    {
        $output = trim($output);
        if ($output === '') {
            return null;
        }

        return mb_strcut($output, 0, (int) config('heimdeye.runner.output_limit', 8192));
    }
}
