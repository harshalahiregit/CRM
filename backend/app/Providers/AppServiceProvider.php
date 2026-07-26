<?php

namespace App\Providers;

use App\Services\Customer\CustomerDirectoryService;
use App\Services\Helpdesk\Contracts\CustomerServiceContract;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Helpdesk (and other modules) resolve customer data through this
        // contract. Now backed by the real Customer module; MockCustomerService
        // is retained only as a reference/testing fallback.
        $this->app->bind(CustomerServiceContract::class, CustomerDirectoryService::class);
        // SlaService caches each tenant's SLA targets + paused/closed status sets
        // for the life of the request. That cache is what stops compute() from
        // querying once per ticket — a 31-ticket list costs 2 config queries
        // instead of 62 — so the service must be resolved ONCE per request, not
        // rebuilt at each injection point.
        //
        // `scoped` rather than `singleton`: identical under PHP-FPM, but a
        // singleton under Octane would survive the request and keep serving stale
        // targets after an admin edits them. `scoped` is dropped at the request
        // boundary, which is exactly the lifetime the cache should have.
        $this->app->scoped(\App\Services\Helpdesk\SlaService::class);

        // Vendor-neutral AI provider — resolved from config('ai.provider').
        $this->app->bind(
            \App\Contracts\AI\AIProviderInterface::class,
            fn () => \App\Services\AI\AIProviderFactory::make()
        );

        // Payroll attendance boundary — placeholder until SangoeTrack integration.
        // Swap this binding for a SangoeTrackAttendanceProvider to go live; payroll
        // logic depends only on the AttendanceProvider interface.
        $this->app->bind(
            \App\Contracts\Hr\AttendanceProvider::class,
            \App\Services\Hr\Attendance\PlaceholderAttendanceProvider::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
