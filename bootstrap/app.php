<?php

use App\Http\Middleware\AuthenticateWithCloudflareAccess;
use App\Http\Middleware\LogInTemplateAdmin;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Cloudflare Access i el mode plantilla han d'iniciar la sessió abans que el panell comprovi si n'hi ha.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, AuthenticateWithCloudflareAccess::class);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, LogInTemplateAdmin::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
