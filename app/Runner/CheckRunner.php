<?php

namespace App\Runner;

use App\Enums\Executor as ExecutorType;
use App\Models\Check;
use App\Models\CheckVersion;
use App\Models\Student;
use App\Runner\Executors\AnsibleExecutor;
use App\Runner\Executors\Executor;
use App\Runner\Executors\LocalExecutor;
use App\Runner\Executors\SshExecutor;
use App\Support\VariableResolver;
use InvalidArgumentException;

/** Prepara el context d'un check per a un alumne i l'executa. No desa res. */
class CheckRunner
{
    public function __construct(private VariableResolver $resolver) {}

    /** Executa la versió indicada (per provar un esborrany) o la vigent. */
    public function run(Check $check, Student $student, ?CheckVersion $version = null): CheckResult
    {
        $version ??= $check->currentVersion;
        if ($version === null) {
            return CheckResult::error('El check no té codi');
        }

        $executor = self::executor($check->executor);
        if ($executor === null) {
            return CheckResult::error("L'executor {$check->executor->value} encara no està implementat");
        }

        $builder = new ContextBuilder($this->resolver);
        try {
            $context = $builder->build(
                $check->task->block->module,
                $student,
                $check->executor,
                $version->codi,
                $version->temps_max_seg,
                Params::values($version->parametres),
            );

            return $executor->execute($context)->masked($context->mask);
        } catch (InvalidArgumentException $e) {
            return CheckResult::error($e->getMessage());
        } finally {
            $builder->cleanup();
        }
    }

    public static function executor(ExecutorType $type): ?Executor
    {
        return match ($type) {
            ExecutorType::Local => app(LocalExecutor::class),
            ExecutorType::Ssh => app(SshExecutor::class),
            ExecutorType::Ansible => app(AnsibleExecutor::class),
            default => null,
        };
    }
}
