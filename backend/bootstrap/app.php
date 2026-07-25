<?php

use App\Exceptions\BusinessException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web:      __DIR__.'/../routes/web.php',
        api:      __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health:   '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // CORS — allow frontend dev server
        $middleware->validateCsrfTokens(except: ['api/*']);

        // Sanctum stateful domains
        $middleware->statefulApi();
        
        // Register custom middleware aliases
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
            'vendor.portal' => \App\Http\Middleware\EnsureVendorPortalAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Log every caught exception with full stack context
        $exceptions->report(function (\Throwable $e) {
            Log::channel('errors')->error($e->getMessage(), [
                'exception' => get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'trace'     => $e->getTraceAsString(),
            ]);
        });

        // JSON error responses for API routes
        $exceptions->render(function (\Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                // Preserve the existing {status, message, errors} shape the frontend
                // was already built against for form-validation failures.
                if ($e instanceof ValidationException) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Validation failed',
                        'errors'  => $e->errors(),
                    ], $e->status);
                }

                // Auth failures must return 401 (not the generic 500) so the
                // frontend's axios interceptor can catch it and redirect to login.
                if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Unauthenticated.',
                    ], 401);
                }

                $status = $e instanceof BusinessException
                    ? $e->getStatusCode()
                    : (method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500);

                return response()->json([
                    'status'  => 'error',
                    'message' => $e->getMessage() ?: 'Server error',
                ], $status);
            }
        });
    })->create();
