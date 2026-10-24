<?php

namespace App\Runner;

use App\Enums\ActionStatus;
use App\Enums\CheckStatus;
use App\Enums\Executor;
use App\Enums\RunOrigin;
use App\Jobs\RunCheck;
use App\Models\Action;
use App\Models\ActionRun;
use App\Models\ActionVersion;
use App\Models\Check;
use App\Models\HostType;
use App\Models\RunBatch;
use App\Models\Student;
use App\Models\User;
use App\Runner\Ansible\AnsibleRunner;
use App\Runner\Ansible\InventoryBuilder;
use App\Support\VariableResolver;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Executa una acció sobre els alumnes i equips triats i en desa el resultat per alumne.
 *
 * Amb Ansible hi ha una sola execució d'ansible-playbook per a tots els alumnes; el resultat
 * es reparteix per alumne a partir dels seus hosts (i dels globals, que compten per a tots).
 */
class ActionRunner
{
    public function __construct(
        private VariableResolver $resolver,
        private AnsibleRunner $ansible,
    ) {}

    /**
     * @param  Collection<int, Student>  $students
     * @param  list<string>  $hostTypes  noms dels tipus d'equip; buit = tots els de l'alumne
     * @param  array<string, string>  $params
     *
     * @throws InvalidArgumentException si l'acció no es pot executar així
     */
    public function run(Action $action, Collection $students, array $hostTypes = [], array $params = [], bool $checkMode = false, ?User $user = null): RunBatch
    {
        $version = $action->currentVersion ?? throw new InvalidArgumentException("L'acció no té codi");
        $params = Params::values($version->parametres, $params);
        if ($checkMode && $action->executor !== Executor::Ansible) {
            throw new InvalidArgumentException('Només es poden simular les accions d\'Ansible');
        }
        if (! in_array($action->executor, [Executor::Ansible, Executor::Ssh, Executor::Local], true)) {
            throw new InvalidArgumentException("L'executor {$action->executor->value} encara no està implementat");
        }

        $module = $action->module;
        $types = $hostTypes === [] ? $module->hostTypes : $module->hostTypes->whereIn('nom', $hostTypes);
        if ($unknown = array_diff($hostTypes, $types->pluck('nom')->all())) {
            throw new InvalidArgumentException('No existeix el tipus d\'equip '.implode(', ', $unknown));
        }

        $batch = RunBatch::create([
            'modul_id' => $module->id,
            'origen' => RunOrigin::Action,
            'llancat_per' => $user?->id,
            'data_inici' => now(),
            'totals' => ['expected' => $students->count(), 'es_simulacio' => $checkMode],
        ]);

        $record = fn (Student $student, ActionStatus $status, array $counts, ?string $output, $startedAt) => ActionRun::create([
            'execucio_id' => $batch->id,
            'accio_id' => $action->id,
            'versio_accio_id' => $version->id,
            'alumne_id' => $student->id,
            'llancat_per' => $user?->id,
            'es_simulacio' => $checkMode,
            'estat' => $status,
            ...$counts,
            'parametres' => $params ?: null,
            'sortida' => $output,
            'data_inici' => $startedAt,
            'data_fi' => now(),
        ]);

        $builder = new ContextBuilder($this->resolver);
        try {
            if ($action->executor === Executor::Ansible) {
                $this->runAnsible($builder, $action, $version, $students, $types, $params, $checkMode, $record);
            } else {
                foreach ($students as $student) {
                    $startedAt = now();
                    try {
                        $context = $builder->build($module, $student, $action->executor, $version->codi, $version->temps_max_seg, $params, onlyTypes: $hostTypes ?: null);
                        $result = CheckRunner::executor($action->executor)->execute($context)->masked($context->mask);
                    } catch (InvalidArgumentException $e) {
                        $result = CheckResult::error($e->getMessage());
                    }
                    $record($student, $this->statusFor($result->status), [], trim($result->message."\n\n".$result->output), $startedAt);
                }
            }
        } finally {
            $builder->cleanup();
        }

        $batch->refreshTotals();

        if (! $checkMode && $version->checks_verificacio) {
            $this->verify($action, $version->checks_verificacio, $students);
        }

        return $batch;
    }

    /**
     * @param  Collection<int, Student>  $students
     * @param  Collection<int, HostType>  $types
     * @param  array<string, string>  $params
     */
    private function runAnsible(ContextBuilder $builder, Action $action, ActionVersion $version, Collection $students, Collection $types, array $params, bool $checkMode, callable $record): void
    {
        $startedAt = now();
        $inventory = new InventoryBuilder($this->resolver, $builder->files);
        $secrets = $builder->secretsFor($action->module, $version->codi);

        $result = $this->ansible->run(
            $builder->files,
            $version->codi,
            $inventory->build($action->module, $students, $types),
            [...$params, ...$secrets],
            $checkMode,
            $version->temps_max_seg,
        );
        $mask = [...array_values($secrets), ...$inventory->mask()];

        foreach ($students as $student) {
            $hosts = array_keys(array_filter($inventory->hostMap(), fn ($id) => $id === null || $id === $student->id));
            $summary = $result->summarize($hosts);
            $record(
                $student,
                $summary['status'],
                ['ok' => $summary['ok'], 'changed' => $summary['changed'], 'failed' => $summary['failed']],
                Masker::mask(trim($summary['message']."\n\n".$summary['output']), $mask),
                $startedAt,
            );
        }
    }

    private function statusFor(CheckStatus $status): ActionStatus
    {
        return match ($status) {
            CheckStatus::Pass => ActionStatus::Ok,
            CheckStatus::Fail => ActionStatus::Failed,
            CheckStatus::Unavailable => ActionStatus::Unavailable,
            CheckStatus::Timeout => ActionStatus::Timeout,
            CheckStatus::Error => ActionStatus::Error,
        };
    }

    /**
     * Llança els checks vinculats sobre els mateixos alumnes per confirmar l'efecte.
     *
     * @param  list<int>  $checkIds
     */
    private function verify(Action $action, array $checkIds, Collection $students): void
    {
        $checks = Check::whereIn('id', $checkIds)->get();
        if ($checks->isEmpty()) {
            return;
        }

        $batch = RunBatch::create([
            'modul_id' => $action->modul_id,
            'origen' => RunOrigin::Manual,
            'data_inici' => now(),
            'totals' => ['expected' => $checks->count() * $students->count()],
        ]);
        foreach ($checks as $check) {
            foreach ($students as $student) {
                RunCheck::dispatch($batch, $check, $student);
            }
        }
    }
}
