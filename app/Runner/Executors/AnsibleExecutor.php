<?php

namespace App\Runner\Executors;

use App\Enums\ActionStatus;
use App\Enums\CheckStatus;
use App\Runner\Ansible\AnsibleRunner;
use App\Runner\CheckResult;
use App\Runner\ExecutionContext;
use App\Runner\TempFiles;

/**
 * Executa un playbook com a check: assolit si cap tasca falla i tots els equips responen.
 * El playbook tria els equips amb hosts: (pve, vm_dns_windows...).
 */
class AnsibleExecutor implements Executor
{
    public function __construct(private AnsibleRunner $ansible) {}

    public function execute(ExecutionContext $context): CheckResult
    {
        $result = $this->ansible->run(
            $context->files ?? new TempFiles,
            $context->code,
            $context->inventory ?? [],
            $context->extraVars,
            $context->checkMode,
            $context->timeout,
        );
        $summary = $result->summarize(array_keys($result->stats));

        return new CheckResult(
            match ($summary['status']) {
                ActionStatus::Ok, ActionStatus::Changed => CheckStatus::Pass,
                ActionStatus::Failed => CheckStatus::Fail,
                ActionStatus::Unavailable => CheckStatus::Unavailable,
                ActionStatus::Timeout => CheckStatus::Timeout,
                ActionStatus::Error => CheckStatus::Error,
            },
            $summary['message'],
            $summary['output'],
            $result->exitCode,
            $result->durationMs,
        );
    }
}
