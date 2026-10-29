<?php

namespace App\Filament\Student\Pages;

use App\Filament\Pages\Help;

/** L'ajuda al panell dels alumnes: només les pàgines per a alumnes (o per a tots). */
class StudentHelp extends Help
{
    protected static string $audience = 'alumnes';

    /** El panell dels alumnes té el menú a dalt, sense grups. */
    protected static string|\UnitEnum|null $navigationGroup = null;
}
