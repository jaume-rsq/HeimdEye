<?php

namespace App\Runner\Executors;

use App\Enums\ActionStatus;
use App\Enums\CheckStatus;
use App\Runner\Ansible\AnsibleRunner;
use App\Runner\CheckResult;
use App\Runner\ExecutionContext;
use App\Runner\TempFiles;

/**
 * Executa un playbook com a check: assolit si cap tasca falla, tots els equips responen i no
 * canvia res (un check només llegeix). El playbook tria els equips amb hosts: (pve, vm_dns_windows...).
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
        $summary = $result->summarize($context->hosts);
        if ($summary['status'] === ActionStatus::Changed) {
            return new CheckResult(
                CheckStatus::Error,
                "El check ha modificat l'equip (changed={$summary['changed']}): un check només pot llegir. Posa changed_when: false a les tasques que no canvien res.",
                $summary['output'],
                $result->exitCode,
                $result->durationMs,
            );
        }

        return new CheckResult(
            match ($summary['status']) {
                ActionStatus::Ok => CheckStatus::Pass,
                ActionStatus::Changed => CheckStatus::Error,
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
