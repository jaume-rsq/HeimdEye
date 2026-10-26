<?php

namespace App\Models\Concerns;

use App\Runner\CodeHeaders;

/**
 * Camps comuns de versions_check i versions_accio: el codi (amb les capçaleres
 * "# hosts:" i "# credencial:"), un comentari opcional, els paràmetres declarats i el temps màxim.
 */
trait ExecutableVersion
{
    public function initializeExecutableVersion(): void
    {
        $this->mergeFillable(['versio', 'codi', 'comentari', 'parametres', 'temps_max_seg', 'creat_per']);
        $this->mergeCasts(['parametres' => 'array']);
    }

    public function headers(): CodeHeaders
    {
        return CodeHeaders::parse($this->codi);
    }
}
