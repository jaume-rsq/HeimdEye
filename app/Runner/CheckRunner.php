<?php

namespace App\Runner;

use App\Enums\Executor as ExecutorType;
use App\Enums\RunOrigin;
use App\Jobs\RunCheck;
use App\Models\Check;
use App\Models\CheckVersion;
use App\Models\RunBatch;
use App\Models\Student;
use App\Models\User;
use App\Runner\Executors\AnsibleExecutor;
use App\Runner\Executors\Executor;
use App\Runner\Executors\LocalExecutor;
use App\Runner\Executors\SshExecutor;
use App\Support\VariableResolver;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

/**
 * Executa un check per a un alumne. run() només en retorna el resultat; launch() el posa a la cua
 * per als alumnes triats, igual que ActionRunner::launch: un treball per alumne amb la versió vigent.
 */
class CheckRunner
{
    public function __construct(
        private VariableResolver $resolver,
        private CodeValidator $validator,
    ) {}

    /**
     * Valida el check, crea el lot i posa a la cua un treball per alumne amb la versió vigent
     * (la de quan es llança, encara que després se'n desi una altra). Amb $sync, els treballs
     * s'executen ara mateix, un rere l'altre i sense reintents.
     *
     * @param  Collection<int, Student>  $students
     *
     * @throws InvalidArgumentException si el check no es pot executar
     */
    public function launch(Check $check, Collection $students, ?User $user = null, bool $sync = false): RunBatch
    {
        $version = $check->currentVersion ?? throw new InvalidArgumentException('El check no té codi');
        $this->prepare($check, $version);

        $batch = RunBatch::create([
            'modul_id' => $check->module->id,
            'origen' => RunOrigin::Manual,
            'llancat_per' => $user?->id,
            'data_inici' => now(),
            'totals' => ['expected' => $students->count()],
        ]);

        foreach ($students as $student) {
            $job = new RunCheck($batch, $check, $student, $version);
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
     * Comprova que la versió es pot executar: executor implementat, paràmetres amb valor per
     * defecte, grups d'equips i secrets (CodeValidator).
     *
     * @throws InvalidArgumentException
     */
    public function prepare(Check $check, CheckVersion $version): void
    {
        Params::values($version->parametres);
        if (self::executor($check->executor) === null) {
            throw new InvalidArgumentException("L'executor {$check->executor->value} encara no està implementat");
        }
        $this->validator->validate($check->module, $check->executor, $version->codi);
    }

    /** Executa la versió indicada (per provar un esborrany o la que s'ha posat a la cua) o la vigent. */
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
                $check->module,
                $student,
                $check->executor,
                $version->codi,
                $version->temps_max_seg,
                Params::values($version->parametres),
            );

            $result = $executor->execute($context);
            if ($check->executor !== ExecutorType::Ansible) {
                $result = $result->skippedIfAsked();
            }

            return $result->masked($context->mask);
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
