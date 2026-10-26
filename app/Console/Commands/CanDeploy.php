<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** El fa servir deploy/deploy.sh: no es desplega amb feines a la cua ni just abans de la ronda nocturna. */
class CanDeploy extends Command
{
    protected $signature = 'heimdeye:can-deploy';

    protected $description = 'Surt amb 0 si ara es pot desplegar (cua buida i lluny de la ronda nocturna)';

    public function handle(): int
    {
        if (config('queue.default') === 'database') {
            $jobs = DB::table(config('queue.connections.database.table', 'jobs'))->count();
            if ($jobs > 0) {
                $this->line("No: hi ha {$jobs} feines a la cua.");

                return self::FAILURE;
            }
        }

        $now = CarbonImmutable::now();
        $nightly = $now->setTimeFromTimeString(config('heimdeye.runner.nightly_at'));
        if ($nightly->lessThan($now)) {
            $nightly = $nightly->addDay();
        }
        $quiet = (int) config('heimdeye.deploy.quiet_before_nightly_min');
        if ($now->diffInMinutes($nightly) < $quiet) {
            $this->line("No: la ronda nocturna comença a les {$nightly->format('H:i')}.");

            return self::FAILURE;
        }

        $this->line('Sí');

        return self::SUCCESS;
    }
}
