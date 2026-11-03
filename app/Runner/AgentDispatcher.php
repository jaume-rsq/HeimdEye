<?php

namespace App\Runner;

use App\Enums\ActionStatus;
use App\Enums\Executor;
use App\Models\Action;
use App\Models\ActionRun;
use App\Models\ActionVersion;
use App\Models\RunBatch;
use App\Models\Student;
use App\Models\User;
use App\Support\VariableResolver;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Encua la feina de l'executor "agent": en comptes de connectar-se als equips, HeimdEye deixa la
 * feina pendent i és l'agent de la instància de l'alumne qui la recull (long poll) i l'executa en
 * local. Una feina per rol de la capçalera "# hosts:" i per alumne, lligada a una instància quan
 * l'agent la recull. Caduca sola si no la recull ningú a temps.
 */
class AgentDispatcher
{
    public function __construct(private VariableResolver $resolver) {}

    /**
     * @param  Collection<int, Student>  $students
     * @param  array<string, string>  $params  valors dels paràmetres (ja validats amb Params::values)
     */
    public function enqueue(Action $action, ActionVersion $version, Collection $students, array $params, ?User $user, RunBatch $batch): void
    {
        $rols = CodeHeaders::parse($version->codi)->hosts;
        $expiry = $action->agentExpirySeconds();

        foreach ($students as $student) {
            $this->enqueueForStudent($action, $version, $student, $rols, $params, $user, $batch, $expiry);
        }
    }

    /**
     * @param  list<string>  $rols
     * @param  array<string, string>  $params
     */
    private function enqueueForStudent(Action $action, ActionVersion $version, Student $student, array $rols, array $params, ?User $user, RunBatch $batch, int $expiry): void
    {
        $base = [
            'execucio_id' => $batch->id,
            'accio_id' => $action->id,
            'versio_accio_id' => $version->id,
            'alumne_id' => $student->id,
            'llancat_per' => $user?->id,
            'es_simulacio' => false,
            'data_inici' => now(),
        ];

        $builder = new ContextBuilder($this->resolver);
        try {
            $context = $builder->build($action->module, $student, Executor::Agent, $version->codi, $version->temps_max_seg, $params);
        } catch (InvalidArgumentException $e) {
            // El codi no es pot preparar per a aquest alumne (li falta una variable, un secret...).
            ActionRun::create([...$base, 'estat' => ActionStatus::Error, 'sortida' => $e->getMessage(), 'data_fi' => now()]);
            $builder->cleanup();

            return;
        }

        $payload = [
            'code' => $context->code,
            'env' => $context->env,
            'timeout' => $version->temps_max_seg,
        ];
        $mask = $context->mask;
        $params = array_intersect_key($context->extraVars, $params);
        $builder->cleanup();

        foreach ($rols as $rol) {
            ActionRun::create([
                ...$base,
                'instancia_id' => null,
                'rol_agent' => $rol,
                'estat' => ActionStatus::Pendent,
                'parametres' => $params ?: null,
                'payload_agent' => $payload,
                'mascara_agent' => $mask ?: null,
                'caduca_a' => now()->addSeconds($expiry),
            ]);
        }
    }
}
