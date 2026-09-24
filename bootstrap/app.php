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

        // Hiding a sidebar link is not access control. Every permission-gated
        // module must also refuse the bare URL, or anyone who types or guesses
        // the path walks straight in. Use it as ->middleware('permission:x.view')
        // on the module's route group.
        // Per-app CSRF cookie name: the default "XSRF-TOKEN" is shared with every
        // other Laravel app on this host (cookies ignore the port), which is a
        // guaranteed 419 when the vendor portal is open in the same browser.
        // replaceInGroup, not replace: the CSRF middleware lives in the `web`
        // group, and replace() only rewrites the global stack.
        $middleware->replaceInGroup(
            'web',
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \App\Http\Middleware\ValidateCsrfToken::class,
        );

        $middleware->alias([
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'integration.key' => \App\Http\Middleware\EnsureIntegrationKey::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The public account-deletion form is used by signed-out members who
        // leave the page to read the emailed code. By the time they come back,
        // the page's CSRF token can be stale (expired session, or another tab
        // logging in/out rotated it). A bare "419 Page Expired" is a dead end
        // for them, so send them back to the form, which now carries a fresh
        // token, with a note to retry. CSRF itself stays enforced.
        // By the time render callbacks run, the TokenMismatchException has been
        // wrapped in a 419 HttpException, so match on its cause.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, Request $request) {
            if (! $e->getPrevious() instanceof \Illuminate\Session\TokenMismatchException
                || ! $request->routeIs('public.account-deletion.*')) {
                return null;
            }

            $onCodeStep = $request->session()->get('account_deletion.step') === 'code';

            return redirect()->to(route('public.account-deletion').'#request')
                ->withInput($request->except(['_token', 'code']))
                ->withErrors($onCodeStep
                    ? ['code' => 'This page expired. Please enter the code again.']
                    : ['email' => 'This page expired. Please enter your email again.']);
        });

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
