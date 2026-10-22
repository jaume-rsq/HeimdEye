<?php

namespace App\Enums;

/**
 * Rol d'un usuari de l'aplicació.
 */
enum Role: string
{
    case Admin = 'admin';
    case Teacher = 'professor';
    case Student = 'alumne';
}
