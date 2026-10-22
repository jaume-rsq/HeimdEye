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

    public function passed(): bool
    {
        return $this->status === CheckStatus::Pass;
    }
}
