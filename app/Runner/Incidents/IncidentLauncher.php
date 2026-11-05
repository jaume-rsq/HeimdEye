<?php

namespace App\Runner\Incidents;

use App\Enums\Executor;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\IncidentLaunch;
use App\Models\IncidentOption;
use App\Models\IncidentStudent;
use App\Models\Student;
use App\Models\User;
use App\Runner\ActionRunner;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Tria les avaries de cada alumne i les injecta amb el runner d'accions de sempre: un llançament
 * de l'acció per opció, amb els alumnes que l'han rebuda. Cada alumne i opció queda a
 * incidencies_alumne lligat al lot de l'acció, i IncidentTracker en segueix l'estat.
 */
class IncidentLauncher
{
    public const MODES = ['aleatori' => 'aleatori', 'igual' => 'igual per a tothom'];

    public function __construct(private ActionRunner $actions) {}

    /**
     * Proposta de qui rep què. Aleatori: sorteig per pes, sense repetir opcions que l'alumne ja ha
     * tingut en aquesta incidència (si ja les ha tingudes totes, torna a començar). Igual: l'opció
     * triada per a tothom. Els alumnes amb una incidència activa d'aquesta definició no hi surten.
     *
     * @param  Collection<int, Student>  $students
     * @param  callable(int): int|null  $random  per als tests: enter aleatori entre 0 i max-1
     * @return array{plan: array<int, list<int>>, skipped: list<int>}
     */
    public function propose(Incident $incident, Collection $students, string $mode, int $count = 1, ?int $optionId = null, ?callable $random = null): array
    {
        $options = $incident->options()->get();
        if ($options->isEmpty()) {
            throw new InvalidArgumentException('La incidència no té cap opció');
        }
        $random ??= fn (int $max) => random_int(0, $max - 1);
        $count = max(1, min($count, $options->count()));

        $studentIds = $students->map(fn (Student $s) => $s->id)->all();
        $busy = $this->busyStudentIds($incident, $studentIds);
        $history = IncidentStudent::query()
            ->where('incidencia_id', $incident->id)
            ->whereIn('alumne_id', $studentIds)
            ->whereNotNull('opcio_id')
            ->get(['alumne_id', 'opcio_id'])
            ->groupBy('alumne_id')
            ->map(fn (Collection $rows) => $rows->pluck('opcio_id')->unique()->all());

        $plan = [];
        foreach ($students as $student) {
            if (in_array($student->id, $busy, true)) {
                continue;
            }
            if ($mode === 'igual') {
                $option = $options->firstWhere('id', $optionId) ?? throw new InvalidArgumentException('Tria l\'opció que rebran tots');
                $plan[$student->id] = [$option->id];

                continue;
            }
            $plan[$student->id] = $this->draw($options, $history[$student->id] ?? [], $count, $random);
        }

        return ['plan' => $plan, 'skipped' => array_values(array_intersect($studentIds, $busy))];
    }

    /**
     * Injecta el pla: crea el llançament i, per a cada opció, llança la seva acció als alumnes que
     * l'han rebuda. Si una opció no es pot llançar (codi invàlid...), els seus alumnes queden en error.
     *
     * @param  array<int, list<int>>  $plan  alumne_id => ids d'opció
     */
    public function launch(Incident $incident, array $plan, string $mode, int $count, ?CarbonInterface $deadline, ?User $user, bool $sync = false): IncidentLaunch
    {
        $options = $incident->options()->with('action.currentVersion', 'action.module')->get()->keyBy('id');
        $plan = array_filter(array_map(fn (array $ids) => array_values(array_unique(array_map('intval', $ids))), $plan));
        if ($plan === []) {
            throw new InvalidArgumentException('Cap alumne no rep cap avaria');
        }
        foreach ($plan as $ids) {
            if ($unknown = array_diff($ids, $options->keys()->all())) {
                throw new InvalidArgumentException('L\'opció '.implode(', ', $unknown).' no és d\'aquesta incidència');
            }
        }

        $launch = IncidentLaunch::create([
            'incidencia_id' => $incident->id,
            'llancat_per' => $user?->id,
            'mode' => $mode,
            'quantitat' => $count,
            'data_limit' => $deadline,
        ]);

        // Opció => alumnes, per llançar cada acció una sola vegada.
        $byOption = [];
        foreach ($plan as $studentId => $ids) {
            foreach ($ids as $id) {
                $byOption[$id][] = $studentId;
            }
        }

        foreach ($byOption as $optionId => $studentIds) {
            $this->inject($launch, $options[$optionId], $studentIds, $user, $sync);
        }

        return $launch;
    }

    /**
     * Llança l'acció de reparació de l'opció a l'alumne i marca la incidència com a reparada.
     *
     * @throws InvalidArgumentException si l'opció no té acció de reparació o no es pot llançar
     */
    public function repair(IncidentStudent $row, ?User $user, bool $sync = false): void
    {
        $option = $row->option ?? throw new InvalidArgumentException('L\'opció ja no existeix');
        $action = $option->repairAction ?? throw new InvalidArgumentException('L\'opció no té acció de reparació');

        $this->actions->launch($action, collect([$row->student]), $this->hostTypes($row->incident, $action->executor), $option->params(), false, $user, $sync);
        $row->update(['estat' => IncidentStatus::Reparada, 'tancat_per' => $user?->id, 'missatge' => 'Reparada amb '.$action->nom]);
    }

    /** @param  list<int>  $studentIds */
    private function inject(IncidentLaunch $launch, IncidentOption $option, array $studentIds, ?User $user, bool $sync): void
    {
        $rows = DB::transaction(fn () => collect($studentIds)->map(fn (int $id) => IncidentStudent::create([
            'incidencia_id' => $launch->incidencia_id,
            'llancament_id' => $launch->id,
            'opcio_id' => $option->id,
            'nom_opcio' => $option->nom,
            'alumne_id' => $id,
            'estat' => IncidentStatus::Pendent,
        ])));

        $students = Student::whereKey($studentIds)->orderBy('num_taula')->get();
        try {
            $batch = $this->actions->prepareBatch(
                $option->action,
                $students,
                $this->hostTypes($launch->incident, $option->action->executor),
                $option->params(),
                $user,
            );
            // El lot s'enllaça abans d'executar res: així, quan l'acció acaba (fins i tot en
            // síncron), IncidentTracker ja troba la fila.
            IncidentStudent::whereKey($rows->pluck('id')->all())->update(['execucio_id' => $batch->id]);
            $this->actions->dispatchBatch($batch, $option->action, $students, $this->hostTypes($launch->incident, $option->action->executor), $option->params(), $user, $sync);
        } catch (InvalidArgumentException $e) {
            IncidentStudent::whereKey($rows->pluck('id')->all())->update(['estat' => IncidentStatus::Error, 'missatge' => mb_substr($e->getMessage(), 0, 255)]);
        }
    }

    /**
     * El tipus d'equip de la incidència limita on va l'acció amb ssh i Ansible. Amb l'agent (i en
     * local) mana el "# hosts:" de l'acció.
     *
     * @return list<string>
     */
    private function hostTypes(Incident $incident, Executor $executor): array
    {
        return filled($incident->equip) && in_array($executor, [Executor::Ssh, Executor::Ansible], true)
            ? [$incident->equip]
            : [];
    }

    /**
     * @param  list<int>  $studentIds
     * @return list<int>
     */
    private function busyStudentIds(Incident $incident, array $studentIds): array
    {
        return IncidentStudent::query()
            ->where('incidencia_id', $incident->id)
            ->whereIn('alumne_id', $studentIds)
            ->active()
            ->distinct()
            ->pluck('alumne_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  Collection<int, IncidentOption>  $options
     * @param  list<int>  $seen
     * @return list<int>
     */
    private function draw(Collection $options, array $seen, int $count, callable $random): array
    {
        $pool = $options->reject(fn (IncidentOption $o) => in_array($o->id, $seen, true))->values();
        if ($pool->count() < $count) {
            $pool = $options->values(); // ja les ha tingut (gairebé) totes: es torna a començar
        }

        $picked = [];
        while (count($picked) < $count && $pool->isNotEmpty()) {
            $total = $pool->sum(fn (IncidentOption $o) => max(1, $o->pes));
            $ticket = $random($total);
            foreach ($pool as $i => $option) {
                $ticket -= max(1, $option->pes);
                if ($ticket < 0) {
                    $picked[] = $option->id;
                    $pool = $pool->forget($i)->values();
                    break;
                }
            }
        }

        return $picked;
    }
}
