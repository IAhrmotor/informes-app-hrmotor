<?php

use App\Http\Middleware\AuditInternalApiRequest;
use App\Http\Middleware\EnsureCommissionsApiBasicAuth;
use App\Http\Middleware\EnsureInformesAuthenticated;
use App\Http\Middleware\EnsureReportAccess;
use App\Http\Middleware\ThrottleInternalApi;
use App\Http\Middleware\TrustConfiguredProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->replace(TrustProxies::class, TrustConfiguredProxies::class);

        $middleware->alias([
            'internal.api.audit' => AuditInternalApiRequest::class,
            'internal.api.throttle' => ThrottleInternalApi::class,
            'commissions.api.auth' => EnsureCommissionsApiBasicAuth::class,
            'reports.auth' => EnsureInformesAuthenticated::class,
            'report.access' => EnsureReportAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
