<?php

namespace App\Enums;

/**
 * Només els checks obligatoris compten per al percentatge de compleció.
 */
enum CheckKind: string
{
    case Required = 'required';
    case Optional = 'optional';
}
