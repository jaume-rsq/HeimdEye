<?php

use App\Http\Controllers\Api\AgentConsoleController;
use App\Http\Controllers\Api\AgentController;
use App\Http\Middleware\AuthenticateAgent;
use App\Http\Middleware\UnescapeJsonResponse;
use Illuminate\Support\Facades\Route;

// API de l'agent de les instàncies dels alumnes. Autenticació per token (no Cloudflare Access):
// a Cloudflare cal una regla de Bypass per a /api/agent/*. El JSON no escapa els accents.
Route::middleware([UnescapeJsonResponse::class, AuthenticateAgent::class])->prefix('agent')->group(function () {
    Route::post('register', [AgentController::class, 'register'])->name('agent.register');
    Route::get('poll', [AgentController::class, 'poll'])->name('agent.poll');
    Route::post('result', [AgentController::class, 'result'])->name('agent.result');
    // Consola interactiva: un cop el poll ha donat una sessió, l'agent hi parla per aquests dos extrems.
    Route::get('console/{session}/pull', [AgentConsoleController::class, 'pull'])->name('agent.console.pull');
    Route::post('console/{session}/push', [AgentConsoleController::class, 'push'])->name('agent.console.push');
});
