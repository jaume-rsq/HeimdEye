<?php

namespace App\Runner\Incidents;

use App\Enums\ActionStatus;
use App\Enums\CheckStatus;
use App\Enums\IncidentStatus;
use App\Models\ActionRun;
use App\Models\CheckRun;
use App\Models\IncidentStudent;
use App\Models\User;
use App\Runner\CheckRunner;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Segueix cada avaria injectada: quan l'acció acaba, llança el check de resolució (ha de fallar);
 * i cada vegada que es desa una execució d'aquest check per a l'alumne, en treu l'estat:
 * verificant → oberta (falla) o no efectiva (passa); oberta → resolta (passa).
 */
class IncidentTracker
{
    public function __construct(private CheckRunner $checks) {}

    /** Una execució d'acció s'ha desat: si és d'una avaria i ja ha acabat, la fa avançar. */
    public function actionRunSaved(ActionRun $run): void
    {
        if ($run->estat === null || $run->estat->isPending() || $run->es_simulacio) {
            return;
        }

        IncidentStudent::query()
            ->where('execucio_id', $run->execucio_id)
            ->where('alumne_id', $run->alumne_id)
            ->where('estat', IncidentStatus::Pendent)
            ->get()
            ->each(fn (IncidentStudent $row) => $this->syncInjection($row));
    }

    /**
     * Revisa les avaries pendents (p. ex. les que han caducat amb una actualització massiva, que
     * no dispara cap esdeveniment). La crida la tasca programada cada minut.
     */
    public function reconcile(): int
    {
        $rows = IncidentStudent::where('estat', IncidentStatus::Pendent)->whereNotNull('execucio_id')->get();
        $rows->each(fn (IncidentStudent $row) => $this->syncInjection($row));

        return $rows->count();
    }

    /**
     * Mira si totes les execucions de l'acció de l'alumne (una per rol amb l'agent) han acabat.
     * Si alguna ha fallat, error (o caducada); si totes han anat bé, llança el check.
     */
    public function syncInjection(IncidentStudent $row): void
    {
        $runs = $row->actionRuns()->get();
        if ($runs->isEmpty() || $runs->contains(fn (ActionRun $r) => $r->estat->isPending())) {
            return;
        }

        if ($runs->contains(fn (ActionRun $r) => $r->estat === ActionStatus::Caducada)) {
            $row->update(['estat' => IncidentStatus::Caducada, 'missatge' => 'L\'agent no ha recollit l\'acció a temps']);

            return;
        }
        if ($failed = $runs->first(fn (ActionRun $r) => $r->estat->isFailure())) {
            $row->update(['estat' => IncidentStatus::Error, 'missatge' => 'L\'acció ha acabat en '.$failed->estat->value]);

            return;
        }

        $row->update(['estat' => IncidentStatus::Verificant, 'data_injeccio' => $runs->max('data_fi') ?? now()]);
        $this->verify($row);
    }

    /**
     * Llança el check de resolució de l'avaria per a l'alumne. Sense check, l'avaria queda oberta i
     * l'ha de tancar el professor.
     */
    public function verify(IncidentStudent $row, ?User $user = null): bool
    {
        $check = $row->option?->check;
        if ($check === null) {
            if ($row->estat === IncidentStatus::Verificant) {
                $row->update(['estat' => IncidentStatus::Oberta, 'missatge' => 'Sense check de resolució: la tanca el professor']);
            }

            return false;
        }

        try {
            $this->checks->launch($check, collect([$row->student]), $user);
        } catch (InvalidArgumentException $e) {
            Log::warning("Incidència {$row->id}: no es pot llançar el check {$check->id}: {$e->getMessage()}");
            $row->update([
                'estat' => $row->estat === IncidentStatus::Verificant ? IncidentStatus::Oberta : $row->estat,
                'missatge' => mb_substr('No es pot llançar el check: '.$e->getMessage(), 0, 255),
            ]);

            return false;
        }

        return true;
    }

    /** S'ha desat una execució de check: avança les avaries de l'alumne que en depenen. */
    public function checkRecorded(CheckRun $run): void
    {
        if (! in_array($run->estat, [CheckStatus::Pass, CheckStatus::Fail], true)) {
            return; // error, no disponible, temps esgotat...: no diu res de l'avaria
        }

        $rows = IncidentStudent::query()
            ->where('alumne_id', $run->alumne_id)
            ->whereIn('estat', [IncidentStatus::Verificant, IncidentStatus::Oberta])
            ->where('data_injeccio', '<=', $run->data_execucio)
            ->whereHas('option', fn ($q) => $q->where('check_id', $run->check_id))
            ->get();

        foreach ($rows as $row) {
            $passed = $run->estat === CheckStatus::Pass;
            if ($row->estat === IncidentStatus::Verificant) {
                $row->update($passed
                    ? ['estat' => IncidentStatus::NoEfectiva, 'execucio_check_id' => $run->id, 'missatge' => 'El check ja passava just després d\'injectar-la']
                    : ['estat' => IncidentStatus::Oberta, 'missatge' => null]);
            } elseif ($passed) {
                $row->update(['estat' => IncidentStatus::Resolta, 'data_resolucio' => $run->data_execucio, 'execucio_check_id' => $run->id, 'missatge' => null]);
            }
        }
    }
}
