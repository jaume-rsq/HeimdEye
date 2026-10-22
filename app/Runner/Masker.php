<?php

namespace App\Runner;

/** Amaga els valors secrets (contrasenyes) en qualsevol text que es desi o es mostri. */
class Masker
{
    public const MASK = '********';

    /** @param  list<string>  $secrets */
    public static function mask(?string $text, array $secrets): ?string
    {
        if ($text === null) {
            return null;
        }
        foreach ($secrets as $secret) {
            if (strlen($secret) >= 3) {
                $text = str_replace($secret, self::MASK, $text);
            }
        }

        return $text;
    }
}
