<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Action;
use App\Models\Check;
use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Mode demo (HEIMDEYE_DEMO=true): instància pública on qualsevol entra com a professor visitant
 * sense contrasenya. Els visitants poden crear i provar checks i accions, però no tocar
 * l'estructura, l'inventari, els secrets ni els usuaris, ni editar el que no han creat ells.
 * Les execucions tenen un temps màxim i un nombre per hora limitats. Els visitants caducats i
 * el que han creat s'esborren amb heimdeye:demo-cleanup.
 */
final class Demo
{
    /** Domini dels correus dels visitants (.invalid: no és un domini real). */
    public const EMAIL_DOMAIN = 'demo.heimdeye.invalid';

    public static function enabled(): bool
    {
        return (bool) config('heimdeye.demo.enabled');
    }

    /** Visitant de la demo: qualsevol usuari que no sigui administrador, amb el mode demo actiu. */
    public static function restricts(?User $user): bool
    {
        return self::enabled() && $user !== null && ! $user->isAdmin();
    }

    public static function isVisitor(User $user): bool
    {
        return str_ends_with(strtolower($user->email), '@'.self::EMAIL_DOMAIN);
    }

    /** Crea un professor visitant, amb tots els mòduls de la demo assignats. */
    public static function createVisitor(): User
    {
        $suffix = Str::lower(Str::random(6));
        $user = User::create([
            'nom' => 'Visitant '.strtoupper($suffix),
            'email' => "visitant-{$suffix}@".self::EMAIL_DOMAIN,
            'password' => Str::random(40),
            'rol' => Role::Teacher,
            'es_actiu' => true,
        ]);
        $user->modules()->sync(Module::pluck('id'));

        return $user;
    }

    /** Temps màxim d'una execució: el de la versió, retallat al màxim de la demo. */
    public static function timeout(int $seconds): int
    {
        return self::enabled() ? min($seconds, (int) config('heimdeye.demo.max_timeout')) : $seconds;
    }

    /**
     * Compta $count execucions més per al visitant i s'atura si passa del límit per hora.
     *
     * @throws InvalidArgumentException
     */
    public static function consumeRuns(?User $user, int $count): void
    {
        if (! self::restricts($user) || $count === 0) {
            return;
        }

        $key = 'demo-runs:'.$user->id;
        $max = (int) config('heimdeye.demo.runs_per_hour');
        if (RateLimiter::attempts($key) + $count > $max) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);

            throw new InvalidArgumentException("A la demo cada visitant pot fer {$max} execucions per hora. Torna-ho a provar d'aquí a {$minutes} min.");
        }
        RateLimiter::increment($key, 3600, $count);
    }

    /** Un visitant només pot editar o esborrar els checks que ha creat ell (la versió 1 és seva). */
    public static function ownsCheck(User $user, Check $check): bool
    {
        return ! self::restricts($user) || $check->versions()->where('versio', 1)->where('creat_per', $user->id)->exists();
    }

    /** Un visitant només pot editar o esborrar les accions que ha creat ell (la versió 1 és seva). */
    public static function ownsAction(User $user, Action $action): bool
    {
        return ! self::restricts($user) || $action->versions()->where('versio', 1)->where('creat_per', $user->id)->exists();
    }
}
