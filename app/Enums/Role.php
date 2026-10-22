<?php

namespace App\Enums;

/**
 * Rol d'un usuari de l'aplicació.
 */
enum Role: string
{
    case Admin = 'admin';
    case Teacher = 'teacher';
    case Student = 'student';
}
