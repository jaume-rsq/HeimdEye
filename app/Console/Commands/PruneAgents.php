<?php

namespace App\Console\Commands;

use App\Enums\ActionStatus;
use App\Models\ActionRun;
use App\Models\AgentInstance;
use Illuminate\Console\Command;

/**
 * Manteniment de les instàncies d'agent i la seva feina:
 *
 * - marca inactives les instàncies que fa estona que no envien batec (cada long poll compta);
 * - quan una instància passa a inactiva, cancel·la les feines que tenia en curs;
 * - caduca les feines que ningú no ha recollit dins la seva finestra.
 *
 * Es programa cada minut (routes/console.php).
 */
class PruneAgents extends Command
{
    protected $signature = 'heimdeye:agent-prune';

    protected $description = 'Marca inactives les instàncies sense batec i caduca la feina pendent';

    public function handle(): int
    {
        $limit = now()->subSeconds((int) config('heimdeye.agent.inactive_after_seconds', 300));

        $stale = AgentInstance::query()
            ->where('estat', 'activa')
            ->where(fn ($q) => $q->whereNull('darrer_batec')->orWhere('darrer_batec', '<', $limit))
            ->get();

        foreach ($stale as $instance) {
            // Si l'agent ja no respon, s'aturen les feines que tenia en curs.
            ActionRun::query()
                ->where('instancia_id', $instance->id)
                ->where('estat', ActionStatus::Pendent)
                ->update(['estat' => ActionStatus::Caducada, 'data_fi' => now()]);
            $instance->update(['estat' => 'inactiva']);
        }

        $expired = ActionRun::query()
            ->where('estat', ActionStatus::Pendent)
            ->whereNull('recollit_a')
            ->whereNotNull('caduca_a')
            ->where('caduca_a', '<=', now())
            ->update(['estat' => ActionStatus::Caducada, 'data_fi' => now()]);

        $this->line("Instàncies inactives: {$stale->count()}. Feines caducades: {$expired}.");

        return self::SUCCESS;
    }
}
