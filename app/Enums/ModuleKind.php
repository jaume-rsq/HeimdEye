<?php

namespace App\Enums;

/**
 * Un mòdul oficial del currículum o un mòdul lliure que en combina diversos.
 */
enum ModuleKind: string
{
    case Official = 'official';
    case Free = 'free';
}
