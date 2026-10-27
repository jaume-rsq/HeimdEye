<?php

namespace App\Console\Commands;

use App\Models\Action;
use App\Models\Check;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Esborra els visitants de la demo inactius i els checks i les accions que han creat (n'han fet
 * la versió 1). El que han canviat del contingut comú no es desfà aquí: ho fa la restauració nocturna.
 */
class DemoCleanup extends Command
{
    protected $signature = 'heimdeye:demo-cleanup {--hours= : hores d\'inactivitat (per defecte, heimdeye.demo.visitor_hours)}';

    protected $description = 'Esborra els visitants inactius de la demo i el que han creat';

    public function handle(): int
    {
        if (! Demo::enabled()) {
            $this->error('El mode demo no és actiu (HEIMDEYE_DEMO).');

            return self::FAILURE;
        }

        $limit = now()->subHours((int) ($this->option('hours') ?? config('heimdeye.demo.visitor_hours')));
        $active = config('session.driver') === 'database'
            ? DB::table(config('session.table', 'sessions'))->where('last_activity', '>=', $limit->getTimestamp())->whereNotNull('user_id')->pluck('user_id')
            : collect();

        $visitors = User::where('email', 'like', '%@'.Demo::EMAIL_DOMAIN)
            ->where('created_at', '<', $limit)
            ->whereNotIn('id', $active)
            ->get();

        $checks = $actions = 0;
        foreach ($visitors as $visitor) {
            Check::whereHas('versions', fn ($q) => $q->where('versio', 1)->where('creat_per', $visitor->id))
                ->get()->each(function (Check $check) use (&$checks) {
                    $check->delete();
                    $checks++;
                });
            Action::whereHas('versions', fn ($q) => $q->where('versio', 1)->where('creat_per', $visitor->id))
                ->get()->each(function (Action $action) use (&$actions) {
                    $action->delete();
                    $actions++;
                });
            $visitor->delete();
        }

        $this->line("Esborrats {$visitors->count()} visitants, {$checks} checks i {$actions} accions.");

        return self::SUCCESS;
    }
}
