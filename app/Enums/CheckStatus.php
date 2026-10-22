<?php

namespace App\Enums;

/**
 * Resultat automàtic d'una execució. Només pass compta com a assolit.
 */
enum CheckStatus: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case Error = 'error';
    case Unavailable = 'unavailable';
    case Timeout = 'timeout';
}
