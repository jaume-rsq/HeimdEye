<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Middleware\AuthenticateAgent;
use Illuminate\Support\Facades\Route;

// API de l'agent de les instàncies dels alumnes. Autenticació per token (no Cloudflare Access):
// a Cloudflare cal una regla de Bypass per a /api/agent/*.
Route::middleware(AuthenticateAgent::class)->prefix('agent')->group(function () {
    Route::post('register', [AgentController::class, 'register'])->name('agent.register');
    Route::get('poll', [AgentController::class, 'poll'])->name('agent.poll');
    Route::post('result', [AgentController::class, 'result'])->name('agent.result');
});
