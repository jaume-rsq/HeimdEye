<?php

namespace App\Enums;

/**
 * Àmbit d'una variable. Una de mòdul tapa una de curs, que tapa una de global.
 */
enum VariableScope: string
{
    case Global = 'global';
    case Course = 'curs';
    case Module = 'modul';
}
