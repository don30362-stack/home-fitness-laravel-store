<?php

use App\Http\Middleware\EnsureAdminIsActive;
use App\Http\Middleware\EnsureMemberIsActive;
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
        $middleware->statefulApi();
        // Banner link validation must see controls before its own space-only trim.
        $middleware->trimStrings(except: ['link_url']);
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/admin/*') ? null : route('login'),
        );
        $middleware->alias([
            'member.active' => EnsureMemberIsActive::class,
            'admin.active' => EnsureAdminIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
