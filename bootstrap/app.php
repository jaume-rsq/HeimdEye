<?php

use App\Http\Middleware\AuthenticateWithCloudflareAccess;
use App\Http\Middleware\LogInTemplateAdmin;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Cloudflare Access i el mode plantilla han d'iniciar la sessió abans que el panell comprovi si n'hi ha.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, AuthenticateWithCloudflareAccess::class);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, LogInTemplateAdmin::class);

        // La consola de l'agent transporta text on els espais i els salts de línia compten: no s'hi
        // poden retallar ni convertir a null (trencaria l'entrada i la sortida del terminal).
        $exceptConsole = fn (Request $request) => $request->is('api/agent/console/*');
        $middleware->trimStrings(except: [$exceptConsole]);
        $middleware->convertEmptyStringsToNull(except: [$exceptConsole]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
