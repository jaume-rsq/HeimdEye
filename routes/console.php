<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('heimdeye:run --nightly')
    ->dailyAt(config('heimdeye.runner.nightly_at'))
    ->withoutOverlapping();
