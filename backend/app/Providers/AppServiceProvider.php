<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
