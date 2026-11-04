<?php

namespace App\Console\Commands;

use App\Enums\ActionStatus;
use App\Enums\CheckStatus;
use App\Models\ActionRun;
use App\Models\AgentInstance;
use App\Models\AgentTask;
use App\Runner\CheckResult;
use App\Runner\ResultRecorder;
use Illuminate\Console\Command;

/**
 * Manteniment de les instàncies d'agent i la seva feina:
 *
 * - marca inactives les instàncies que fa estona que no envien batec (cada long poll compta);
 * - quan una instància passa a inactiva, cancel·la les feines que tenia en curs (accions i checks);
 * - caduca les feines que ningú no ha recollit dins la seva finestra.
 *
 * Es programa cada minut (routes/console.php).
 */
class PruneAgents extends Command
{
    protected $signature = 'heimdeye:agent-prune';

    protected $description = 'Marca inactives les instàncies sense batec i caduca la feina pendent';

    public function handle(ResultRecorder $recorder): int
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
            AgentTask::query()
                ->where('instancia_id', $instance->id)
                ->where('estat', 'enviada')
                ->get()
                ->each(fn (AgentTask $feina) => $this->closeCheck($recorder, $feina, 'La instància ha deixat de respondre abans de tornar el resultat'));
            $instance->update(['estat' => 'inactiva']);
        }

        $expired = ActionRun::query()
            ->where('estat', ActionStatus::Pendent)
            ->whereNull('recollit_a')
            ->whereNotNull('caduca_a')
            ->where('caduca_a', '<=', now())
            ->update(['estat' => ActionStatus::Caducada, 'data_fi' => now()]);

        $expiredChecks = AgentTask::query()
            ->where('estat', 'pendent')
            ->whereNull('recollit_a')
            ->whereNotNull('caduca_a')
            ->where('caduca_a', '<=', now())
            ->get();
        foreach ($expiredChecks as $feina) {
            $this->closeCheck($recorder, $feina, 'Cap instància no ha recollit el check a temps');
        }

        $this->line("Instàncies inactives: {$stale->count()}. Accions caducades: {$expired}. Checks caducats: {$expiredChecks->count()}.");

        return self::SUCCESS;
    }

    /** Desa el check caducat com a "no disponible" i tanca la feina i el lot. */
    private function closeCheck(ResultRecorder $recorder, AgentTask $feina, string $message): void
    {
        $recorder->record(
            $feina->batch,
            $feina->check,
            $feina->student,
            new CheckResult(CheckStatus::Unavailable, $message),
            $feina->checkVersion,
        );
        $feina->update(['estat' => 'caducada']);
        $feina->batch->refreshTotals();
    }
}
