<?php

namespace App\Runner;

use App\Enums\CheckStatus;

/** Resultat d'executar un check sobre un alumne. */
final readonly class CheckResult
{
    public function __construct(
        public CheckStatus $status,
        public ?string $message = null,
        public ?string $output = null,
        public ?int $exitCode = null,
        public int $durationMs = 0,
    ) {}

    public static function error(string $message): self
    {
        return new self(CheckStatus::Error, $message);
    }

    /** @param  list<string>  $secrets */
    public function masked(array $secrets): self
    {
        return new self(
            $this->status,
            Masker::mask($this->message, $secrets),
            Masker::mask($this->output, $secrets),
            $this->exitCode,
            $this->durationMs,
        );
    }

    /**
     * Si el codi s'ha executat i ha escrit una línia «HEIMDEYE_OMES: motiu», el resultat és omes
     * amb aquest motiu, surti com surti: el check no s'aplica ara (per exemple, el sistema que
     * comprova està apagat perquè l'alumne fa servir l'alternatiu).
     */
    public function skippedIfAsked(): self
    {
        if (! in_array($this->status, [CheckStatus::Pass, CheckStatus::Fail], true)
            || ! preg_match('/^HEIMDEYE_OMES:[ \t]*(.*)$/m', (string) $this->output, $m)) {
            return $this;
        }

        return new self(CheckStatus::Skipped, mb_substr(trim($m[1]) ?: 'Omès', 0, 255), $this->output, $this->exitCode, $this->durationMs);
    }

    public function passed(): bool
    {
        return $this->status === CheckStatus::Pass;
    }
}
