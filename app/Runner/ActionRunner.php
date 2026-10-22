<?php

namespace App\Runner;

use App\Enums\ActionStatus;
use App\Enums\CheckStatus;
use App\Enums\Executor;
use App\Enums\RunOrigin;
use App\Jobs\RunCheck;
use App\Models\Action;
use App\Models\ActionRun;
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
        $params = $this->params($action, $params);
        if ($checkMode && $action->executor !== Executor::Ansible) {
            throw new InvalidArgumentException('Només es poden simular les accions d\'Ansible');
        }
        if (! in_array($action->executor, [Executor::Ansible, Executor::Ssh, Executor::Local], true)) {
            throw new InvalidArgumentException("L'executor {$action->executor->value} encara no està implementat");
        }

        $module = $action->module;
        $types = $hostTypes === [] ? $module->hostTypes : $module->hostTypes->whereIn('name', $hostTypes);
        if ($unknown = array_diff($hostTypes, $types->pluck('name')->all())) {
            throw new InvalidArgumentException('No existeix el tipus d\'equip '.implode(', ', $unknown));
        }

        $batch = RunBatch::create([
            'module_id' => $module->id,
            'origin' => RunOrigin::Action,
            'triggered_by' => $user?->id,
            'started_at' => now(),
            'totals' => ['expected' => $students->count(), 'check_mode' => $checkMode],
        ]);

        $record = fn (Student $student, ActionStatus $status, array $counts, ?string $output, $startedAt) => ActionRun::create([
            'run_batch_id' => $batch->id,
            'action_id' => $action->id,
            'action_version_id' => $version->id,
            'student_id' => $student->id,
            'triggered_by' => $user?->id,
            'check_mode' => $checkMode,
            'status' => $status,
            ...$counts,
            'params' => $params ?: null,
            'output' => $output,
            'started_at' => $startedAt,
            'finished_at' => now(),
        ]);

        $builder = new ContextBuilder($this->resolver);
        try {
            if ($action->executor === Executor::Ansible) {
                $this->runAnsible($builder, $action, $version->code, $students, $types, $params, $checkMode, $record);
            } else {
                foreach ($students as $student) {
                    $startedAt = now();
                    try {
                        $context = $builder->build($module, $student, $action->executor, $version->code, $version->config ?? [], $action->timeout_s, params: $params);
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

        if (! $checkMode && $action->verify_check_ids) {
            $this->verify($action, $students);
        }

        return $batch;
    }

    /**
     * @param  Collection<int, Student>  $students
     * @param  Collection<int, HostType>  $types
     * @param  array<string, string>  $params
     */
    private function runAnsible(ContextBuilder $builder, Action $action, string $code, Collection $students, Collection $types, array $params, bool $checkMode, callable $record): void
    {
        $startedAt = now();
        $inventory = new InventoryBuilder($this->resolver, $builder->files);
        $secrets = $builder->secretsFor($action->module, $code);

        $result = $this->ansible->run(
            $builder->files,
            $code,
            $inventory->build($action->module, $students, $types),
            [...$params, ...$secrets],
            $checkMode,
            $action->timeout_s,
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

    /**
     * Valors dels paràmetres declarats, amb els valors per defecte.
     *
     * @param  array<string, string>  $given
     * @return array<string, string>
     */
    private function params(Action $action, array $given): array
    {
        $declared = collect($action->params ?? [])->keyBy('name');
        if ($unknown = array_diff(array_keys($given), $declared->keys()->all())) {
            throw new InvalidArgumentException('Paràmetre desconegut: '.implode(', ', $unknown));
        }

        $values = [];
        foreach ($declared as $name => $param) {
            $value = $given[$name] ?? $param['default'] ?? null;
            if ($value === null) {
                throw new InvalidArgumentException("Falta el paràmetre {$name}");
            }
            $values[$name] = (string) $value;
        }

        return $values;
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

    /** Llança els checks vinculats sobre els mateixos alumnes per confirmar l'efecte. */
    private function verify(Action $action, Collection $students): void
    {
        $checks = Check::whereIn('id', $action->verify_check_ids)->get();
        if ($checks->isEmpty()) {
            return;
        }

        $batch = RunBatch::create([
            'module_id' => $action->module_id,
            'origin' => RunOrigin::Manual,
            'started_at' => now(),
            'totals' => ['expected' => $checks->count() * $students->count()],
        ]);
        foreach ($checks as $check) {
            foreach ($students as $student) {
                RunCheck::dispatch($batch, $check, $student);
            }
        }
    }
}
