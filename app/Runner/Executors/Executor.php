<?php

namespace App\Runner\Executors;

use App\Runner\CheckResult;
use App\Runner\ExecutionContext;

interface Executor
{
    public function execute(ExecutionContext $context): CheckResult;
}
