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

    /**
     * Com mask(), per a un text que pot haver estat retallat (sortida al límit, missatge a 255):
     * si acaba amb el principi d'un secret, el tall l'ha partit i str_replace no el troba, així que
     * també es tapa. Un final que coincideix per casualitat queda tapat: no es perd res important.
     *
     * @param  list<string>  $secrets
     */
    public static function maskTruncated(?string $text, array $secrets): ?string
    {
        $text = self::mask($text, $secrets);
        if ($text === null) {
            return null;
        }

        foreach ($secrets as $secret) {
            for ($length = min(strlen($secret) - 1, strlen($text)); $length >= 3; $length--) {
                if (str_ends_with($text, substr($secret, 0, $length))) {
                    return substr($text, 0, -$length).self::MASK;
                }
            }
        }

        return $text;
    }
}
