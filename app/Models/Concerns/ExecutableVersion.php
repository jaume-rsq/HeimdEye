<?php

namespace App\Models\Concerns;

use App\Enums\Executor;
use App\Runner\CodeHeaders;

/**
 * Camps comuns de versions_check i versions_accio: el codi (amb les capçaleres
 * "# hosts:"), l'executor amb què es va escriure, un comentari opcional, els paràmetres declarats
 * i el temps màxim. Si no es diu l'executor, la versió pren el del seu check o acció.
 */
trait ExecutableVersion
{
    /** Executor del check o l'acció de la versió (el vigent). */
    abstract protected function ownerExecutor(): ?Executor;

    public static function bootExecutableVersion(): void
    {
        static::creating(function (self $version) {
            $version->executor ??= $version->ownerExecutor();
        });
    }

    public function initializeExecutableVersion(): void
    {
        $this->mergeFillable(['versio', 'executor', 'codi', 'comentari', 'parametres', 'temps_max_seg', 'creat_per']);
        $this->mergeCasts(['parametres' => 'array', 'executor' => Executor::class]);
    }

    /** L'executor de la versió; si no en té (desada sense esdeveniments, p. ex. un seeder), el vigent. */
    public function effectiveExecutor(): ?Executor
    {
        return $this->executor ?? $this->ownerExecutor();
    }

    public function headers(): CodeHeaders
    {
        return CodeHeaders::parse($this->codi);
    }
}
