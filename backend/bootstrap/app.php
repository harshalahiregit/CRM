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
        
        // Idle-timeout enforcement for tracked sessions — defensive, additive.
        $middleware->api(append: [
            \App\Http\Middleware\EnforceIdleTimeout::class,
        ]);

        // Register custom middleware aliases
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
            'vendor.portal' => \App\Http\Middleware\EnsureVendorPortalAccess::class,
            'purchase.vendor.portal' => \App\Http\Middleware\EnsurePurchaseVendorPortalAccess::class,
            'company.portal' => \App\Http\Middleware\EnsureCompanyPortalAccess::class,
            'temp.access' => \App\Http\Middleware\EnsureTemporaryAccessNotExpired::class,
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

                // Everything else goes through ApiErrorMapper, which returns a
                // sentence the user can act on. Returning $e->getMessage() here
                // used to dump a whole failing SQL statement — connection, host,
                // database name, columns and bound values — straight into a toast.
                $mapped = \App\Support\ApiErrorMapper::map($e);

                // Reference ties the friendly message to the logged stack trace, so
                // "error SVR-3F9A21" in a support request is actually findable.
                $reference = strtoupper(substr(md5($e->getFile().$e->getLine().get_class($e)), 0, 6));

                Log::channel('errors')->error('API error returned to client', [
                    'reference' => $reference,
                    'exception' => get_class($e),
                    'raw'       => $e->getMessage(),
                    'file'      => $e->getFile().':'.$e->getLine(),
                ]);

                $payload = [
                    'status'    => 'error',
                    'message'   => $mapped['message'],
                    'reference' => $reference,
                ];

                // Point the form at the offending input when we could identify it.
                if ($mapped['field']) {
                    $payload['errors'] = [$mapped['field'] => [$mapped['hint'] ?: $mapped['message']]];
                }
                // Only as a standalone tip — when a field error was returned the
                // hint IS that field's message, and repeating it reads as noise.
                if ($mapped['hint'] && ! $mapped['field']) {
                    $payload['hint'] = $mapped['hint'];
                }
                // Developers still need the real cause locally.
                if (config('app.debug')) {
                    $payload['debug'] = ['exception' => get_class($e), 'raw' => $e->getMessage()];
                }

                return response()->json($payload, $mapped['status']);
            }
        });
    })->create();
