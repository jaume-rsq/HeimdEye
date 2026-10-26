<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Detecta feines que esperen a la cua de HeimdEye sense que cap worker les processi. */
final class QueueStatus
{
    public const STALE_AFTER_SECONDS = 15;

    /** Avís per al panell, o null si la cua va bé o no és de base de dades. */
    public static function warning(): ?string
    {
        if (config('queue.default') !== 'database') {
            return null;
        }

        $queue = config('heimdeye.runner.queue');
        $waiting = DB::table(config('queue.connections.database.table', 'jobs'))
            ->where('queue', $queue)
            ->whereNull('reserved_at')
            ->where('available_at', '<', now()->subSeconds(self::STALE_AFTER_SECONDS)->getTimestamp())
            ->count();

        return $waiting === 0 ? null
            : "Hi ha {$waiting} feines a la cua «{$queue}» que no s'han començat: cal un worker en marxa (php artisan queue:work --queue={$queue}).";
    }
}
