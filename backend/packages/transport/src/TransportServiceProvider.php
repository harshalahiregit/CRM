<?php

namespace Transport;

use Illuminate\Support\ServiceProvider;
use Transport\Console\ListUnmappedRoles;

/**
 * Sangoé Transport OS — the installation.
 *
 * One line in bootstrap/providers.php brings config and commands:
 *
 *     Transport\TransportServiceProvider::class,
 *
 * and one line in bootstrap/app.php registers the route gate:
 *
 *     'transport.permission' => \Transport\Http\Middleware\EnsureTransportPermission::class,
 *
 * The package follows SIRE's shape in this repository — portable code under
 * packages/, the host wired to it from app/ and bootstrap/ — because that shape
 * already answers the question of running as a module here and standing alone
 * elsewhere, and it keeps three developers out of each other's files while they
 * build different halves of the same module.
 *
 * ARC-01 applies: a layered modular monolith, not microservices. Nothing here
 * is a service boundary; it is a package boundary.
 */
class TransportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/transport.php', 'transport');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/transport.php' => config_path('transport.php'),
        ], 'transport-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ListUnmappedRoles::class,
            ]);
        }
    }
}
