<?php

namespace App\Enums;

/**
 * Què ha llançat un lot d'execucions.
 */
enum RunOrigin: string
{
    case Nightly = 'nightly';
    case Manual = 'manual';
    case Test = 'test';
}
