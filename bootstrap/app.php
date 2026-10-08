<?php

declare(strict_types=1);

use App\Domain\Security\DeniedAccessRecorder;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\RequirePermission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // First in the stack, so even rejected requests carry an id in the logs.
        $middleware->prepend(RequestId::class);

        // Route permission check (INV-17): ->middleware(['auth', 'permission:payment.create'])
        $middleware->alias(['permission' => RequirePermission::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Gates, Policies and Livewire actions refuse by throwing
        // AuthorizationException, which Laravel has already wrapped in an
        // HttpException by the time render callbacks run. Audit the refusal
        // (FR-SEC-010), then return null so Laravel still renders its normal 403.
        // The `permission` middleware audits its own refusals and throws a plain
        // HttpException with no previous exception, so it is not recorded twice.
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getPrevious() instanceof AuthorizationException) {
                app(DeniedAccessRecorder::class)->record($request, $request->user() instanceof User ? $request->user() : null);
            }

            return null;
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
