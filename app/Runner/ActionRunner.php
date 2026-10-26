<?php

namespace App\Runner;

use App\Enums\ActionStatus;
use App\Enums\CheckStatus;
use App\Enums\Executor;
use App\Enums\RunOrigin;
use App\Jobs\RunAction;
use App\Models\Action;
use App\Models\ActionRun;
use App\Models\ActionVersion;
use App\Models\Check;
use App\Models\RunBatch;
use App\Models\Student;
use App\Models\User;
use App\Runner\Ansible\AnsibleRunner;
use App\Support\VariableResolver;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

/**
 * Executa una acció sobre els alumnes i equips triats i en desa el resultat per alumne.
 *
 * Cada alumne és una execució a part (un ansible-playbook o un script per alumne), en un
 * treball de la cua propi: un alumne amb un error o un equip que no respon no afecta els altres,
 * i el temps màxim de la versió és per alumne.
 */
class ActionRunner
{
    public function __construct(
        private VariableResolver $resolver,
        private AnsibleRunner $ansible,
        private CodeValidator $validator,
    ) {}

    /**
     * Valida l'execució, crea el lot i posa a la cua un treball per alumne amb la versió vigent
     * (la de quan es llança, encara que després se'n desi una altra). Amb $sync, els treballs
     * s'executen ara mateix, un rere l'altre.
     *
     * @param  Collection<int, Student>  $students
     * @param  list<string>  $hostTypes  noms dels tipus d'equip; buit = tots els que fa servir el codi
     * @param  array<string, string>  $params
     *
     * @throws InvalidArgumentException si l'acció no es pot executar així
     */
    public function launch(Action $action, Collection $students, array $hostTypes = [], array $params = [], bool $checkMode = false, ?User $user = null, bool $sync = false): RunBatch
    {
        $version = $action->currentVersion ?? throw new InvalidArgumentException("L'acció no té codi");
        $params = $this->prepare($action, $version, $hostTypes, $params, $checkMode);

        $batch = RunBatch::create([
            'modul_id' => $action->modul_id,
            'origen' => RunOrigin::Action,
            'llancat_per' => $user?->id,
            'data_inici' => now(),
            'totals' => ['expected' => $students->count(), 'es_simulacio' => $checkMode],
        ]);
        $verification = $checkMode ? null : $this->verificationBatch($action, $version, $students->count(), $user);

        foreach ($students as $student) {
            $job = new RunAction($batch, $action, $version, $student, $hostTypes, $params, $checkMode, $user, $verification);
            if (! $sync) {
                dispatch($job);

                continue;
            }
            try {
                dispatch_sync($job);
            } catch (Throwable $e) {
                report($e); // el treball ja ho ha desat com a error; es continua amb el següent alumne
            }
        }

        return $batch;
    }

    /**
     * Executa una versió per a un alumne i en retorna el resultat sense desar.
     *
     * @param  list<string>  $hostTypes
     * @param  array<string, string>  $params  valors ja validats amb Params::values
     */
    public function runFor(Action $action, ActionVersion $version, Student $student, array $hostTypes = [], array $params = [], bool $checkMode = false): ActionRun
    {
        $startedAt = now();
        $builder = new ContextBuilder($this->resolver);
        try {
            $context = $builder->build($action->module, $student, $action->executor, $version->codi, $version->temps_max_seg, $params, $checkMode, $hostTypes ?: null);
            // Els paràmetres tal com els ha rebut l'alumne, ja calculats amb les seves variables.
            $params = array_intersect_key($context->extraVars, $params);
            $fields = $action->executor === Executor::Ansible
                ? $this->runAnsible($context)
                : $this->runScript($action->executor, $context);
        } catch (InvalidArgumentException $e) {
            $fields = ['estat' => ActionStatus::Error, 'sortida' => $e->getMessage()];
        } finally {
            $builder->cleanup();
        }

        $run = new ActionRun([
            'accio_id' => $action->id,
            'versio_accio_id' => $version->id,
            'alumne_id' => $student->id,
            'es_simulacio' => $checkMode,
            'parametres' => $params ?: null,
            ...$fields,
            'data_inici' => $startedAt,
            'data_fi' => now(),
        ]);
        $run->setRelation('student', $student);

        return $run;
    }

    /**
     * Prova una versió (també un esborrany sense desar, i d'una acció encara sense desar)
     * i en retorna el resultat per alumne sense desar res ni llançar els checks de verificació.
     *
     * @param  Collection<int, Student>  $students
     * @param  list<string>  $hostTypes
     * @param  array<string, string>  $params
     * @return list<ActionRun> execucions sense desar
     *
     * @throws InvalidArgumentException si l'acció no es pot executar així
     */
    public function preview(Action $action, ActionVersion $version, Collection $students, array $hostTypes = [], array $params = [], bool $checkMode = false): array
    {
        $params = $this->prepare($action, $version, $hostTypes, $params, $checkMode);

        return $students->map(fn (Student $student) => $this->runFor($action, $version, $student, $hostTypes, $params, $checkMode))->values()->all();
    }

    /**
     * Valida l'execució (codi, equips triats i paràmetres) i retorna els valors dels paràmetres.
     *
     * @param  list<string>  $hostTypes
     * @param  array<string, string>  $params
     * @return array<string, string>
     */
    private function prepare(Action $action, ActionVersion $version, array $hostTypes, array $params, bool $checkMode): array
    {
        $params = Params::values($version->parametres, $params);
        if ($checkMode && $action->executor !== Executor::Ansible) {
            throw new InvalidArgumentException('Només es poden simular les accions d\'Ansible');
        }
        if (! in_array($action->executor, [Executor::Ansible, Executor::Ssh, Executor::Local], true)) {
            throw new InvalidArgumentException("L'executor {$action->executor->value} encara no està implementat");
        }

        $module = $action->module;
        if ($unknown = array_diff($hostTypes, $module->hostTypes->pluck('nom')->all())) {
            throw new InvalidArgumentException('No existeix el tipus d\'equip '.implode(', ', $unknown));
        }
        $this->validator->validate($module, $action->executor, $version->codi);
        $types = $this->validator->hostTypes($module, $action->executor, $version->codi);
        if ($types !== null && $hostTypes !== [] && $types->whereIn('nom', $hostTypes)->isEmpty()) {
            throw new InvalidArgumentException('Cap dels tipus d\'equip triats no és dels que fa servir el codi ('.$types->pluck('nom')->implode(', ').')');
        }

        return $params;
    }

    /** @return array<string, mixed> camps de l'execució */
    private function runAnsible(ExecutionContext $context): array
    {
        $result = $this->ansible->run($context->files, $context->code, $context->inventory ?? [], $context->extraVars, $context->checkMode, $context->timeout);
        $summary = $result->summarize($context->hosts);

        return [
            'estat' => $summary['status'],
            'ok' => $summary['ok'],
            'changed' => $summary['changed'],
            'failed' => $summary['failed'],
            'sortida' => Masker::mask(trim($summary['message']."\n\n".$summary['output']), $context->mask),
        ];
    }

    /** @return array<string, mixed> camps de l'execució */
    private function runScript(Executor $executor, ExecutionContext $context): array
    {
        $result = CheckRunner::executor($executor)->execute($context)->masked($context->mask);

        return [
            'estat' => match ($result->status) {
                CheckStatus::Pass => ActionStatus::Ok,
                CheckStatus::Fail => ActionStatus::Failed,
                CheckStatus::Unavailable => ActionStatus::Unavailable,
                CheckStatus::Timeout => ActionStatus::Timeout,
                CheckStatus::Error => ActionStatus::Error,
            },
            'sortida' => trim($result->message."\n\n".$result->output),
        ];
    }

    /** Lot dels checks que confirmen l'efecte de l'acció; cada alumne hi afegeix els seus en acabar. */
    private function verificationBatch(Action $action, ActionVersion $version, int $students, ?User $user): ?RunBatch
    {
        $checks = Check::whereIn('id', $version->checks_verificacio ?? [])->count();
        if ($checks === 0) {
            return null;
        }

        return RunBatch::create([
            'modul_id' => $action->modul_id,
            'origen' => RunOrigin::Manual,
            'llancat_per' => $user?->id,
            'data_inici' => now(),
            'totals' => ['expected' => $checks * $students],
        ]);
    }
}
