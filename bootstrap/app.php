<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \App\Http\Middleware\UpdateUserPresence::class,
        ]);
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // When a session expires mid-request, an exception (e.g. auth) renders a 302
        // redirect to /login outside Inertia's middleware, so it never gets converted
        // to a 303. The browser then re-sends a PUT/PATCH/DELETE to /login and Laravel
        // throws MethodNotAllowedHttpException. Forcing 303 makes the browser follow
        // the redirect as a GET instead.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            if (
                $request->header('X-Inertia')
                && $response->getStatusCode() === 302
                && in_array($request->method(), ['PUT', 'PATCH', 'DELETE'], true)
            ) {
                $response->setStatusCode(303);
            }

            return $response;
        });
    })->create();
