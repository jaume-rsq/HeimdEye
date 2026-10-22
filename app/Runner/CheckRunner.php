<?php

namespace App\Runner;

use App\Enums\Executor as ExecutorType;
use App\Models\Check;
use App\Models\CheckVersion;
use App\Models\Student;
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

        $module = $check->task->block->module;
        $vars = $this->resolver->resolve($student, $module);

        try {
            $config = $this->renderConfig($version->config ?? [], $vars);
        } catch (InvalidArgumentException $e) {
            return CheckResult::error($e->getMessage());
        }

        $credential = $check->credential ?? $module->credential;
        $keyFile = $credential?->secret ? $this->writeKey($credential->secret) : null;

        try {
            return $this->executor($check->executor)?->execute(new ExecutionContext(
                code: $version->code,
                env: $this->resolver->toEnv($vars),
                config: $config,
                timeout: $check->timeout_s,
                sshUser: $credential?->username,
                sshKey: $keyFile ?? config('heimdeye.runner.ssh_key_path'),
            )) ?? CheckResult::error("L'executor {$check->executor->value} encara no està implementat");
        } finally {
            if ($keyFile) {
                @unlink($keyFile);
            }
        }
    }

    private function executor(ExecutorType $type): ?Executor
    {
        return match ($type) {
            ExecutorType::Local => app(LocalExecutor::class),
            ExecutorType::Ssh => app(SshExecutor::class),
            default => null,
        };
    }

    /**
     * Resol les plantilles {{var}} dels valors de text de la config.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $vars
     * @return array<string, mixed>
     */
    private function renderConfig(array $config, array $vars): array
    {
        foreach ($config as $key => $value) {
            if (! is_string($value)) {
                continue;
            }
            if ($missing = $this->resolver->missing($value, $vars)) {
                throw new InvalidArgumentException('Falta la variable '.implode(', ', $missing));
            }
            $config[$key] = $this->resolver->render($value, $vars);
        }

        return $config;
    }

    private function writeKey(string $secret): string
    {
        $path = tempnam(sys_get_temp_dir(), 'heimdeye-key-');
        chmod($path, 0600);
        file_put_contents($path, rtrim($secret)."\n");

        return $path;
    }
}
