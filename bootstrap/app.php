<?php

use App\Http\Middleware\ApplyActiveOffice;
use App\Http\Middleware\EnforceSessionLimits;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // First, so the headers are on every response, including the error pages that cut the request short
        // (a record not found, a session that ran out, a form that failed its token).
        $middleware->web(prepend: [SecurityHeaders::class]);

        $middleware->web(append: [
            EnforceSessionLimits::class,
            ApplyActiveOffice::class,
            HandleInertiaRequests::class,
        ]);

        // Before route binding, so a record is looked up as the office chosen.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ApplyActiveOffice::class);

        // Behind a load balancer / reverse proxy in production: trust only the proxies listed.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : explode(',', $proxies));
        }

        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/dashboard');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return null;
            }

            $status = $e->getStatusCode();

            // Leave 500 to Laravel's own debug page locally, so stack
            // traces remain visible in development.
            if ($status === 500 && config('app.debug')) {
                return null;
            }

            $response = match ($status) {
                403 => Inertia::render('Errors/403', [
                    'message' => 'You do not have permission to perform this action.',
                ]),
                404, 419, 429, 500, 503 => Inertia::render('Errors/Show', ['status' => $status]),
                default => null,
            };

            return $response?->toResponse($request)->setStatusCode($status);
        });
    })->create();
