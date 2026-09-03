<?php

namespace App\Http\Middleware;

use App\Services\Mail\TenantMailConfigurator;
use Closure;
use Illuminate\Http\Request;

/**
 * Point the mailer at the current tenant's Settings → Email SMTP for the whole
 * request, so every email a controller triggers (approval, ticket reply, HR,
 * inventory, task…) uses the admin-configured account rather than .env. Runs on
 * the api group; resolves the tenant from the authenticated user (Sanctum token
 * or session). No user / no setting → leaves the .env fallback in place.
 */
class ConfigureTenantMail
{
    public function __construct(private TenantMailConfigurator $configurator)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        // Sanctum guard resolves the bearer token even before route auth; fall
        // back to the default (session) guard for stateful requests.
        $user = auth('sanctum')->user() ?? $request->user();
        $tenantId = $user?->tenant_id;

        if ($tenantId) {
            $this->configurator->applyForTenant((int) $tenantId);
        }

        return $next($request);
    }
}
