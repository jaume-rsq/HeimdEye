<?php

namespace App\Filament\Student\Pages;

use App\Filament\Pages\Help;

/** L'ajuda al panell dels alumnes: només les pàgines per a alumnes (o per a tots). */
class StudentHelp extends Help
{
    protected static string $audience = 'alumnes';
}
