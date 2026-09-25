<?php

namespace App\Providers;

use App\Domains\Fleet\Contracts\DriverDirectory;
use App\Domains\Fleet\Integration\TransportFleetResourceGateway;
use App\Domains\Fleet\Directory\CompositeDriverDirectory;
use App\Domains\Fleet\Models\DriverProfile;
use App\Support\Transport\DriverNaming;
use App\Domains\Fleet\Directory\CrmDriverDirectory;
use App\Domains\Fleet\Directory\StandaloneDriverDirectory;
use App\Domains\Fleet\Events\EmergencyFuelIssued;
use App\Domains\Fleet\Events\VehicleStatusChanged;
use App\Domains\Fleet\Models\Vehicle;
use App\Domains\Fleet\Observers\VehicleStatusObserver;
use App\Domains\Integration\Events\TelemetryExcursionDetected;
use App\Domains\Integration\Listeners\LogTemperatureExcursion;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * STOS wiring, kept in one place.
 *
 * Laravel's listener auto-discovery only scans app/Listeners, and STOS keeps
 * its listeners inside their domain (app/Domains/Integration/Listeners), so the
 * bindings are declared here explicitly. Without this the excursion event
 * dispatches into silence — no error, just nothing listening, which is the
 * failure mode nobody notices.
 */
class StosServiceProvider extends ServiceProvider
{
    /** Domain events → their listeners. */
    private const LISTENERS = [
        TelemetryExcursionDetected::class => [
            LogTemperatureExcursion::class,
        ],
    ];

    /**
     * Domain events published under their STOS-TM-001 contract names.
     *
     * Our code dispatches typed classes; Developers 1 and 3 subscribe to the
     * documented STRING names. Re-broadcasting here is the adapter between the
     * two, so a consumer never has to import our namespace and we never have to
     * name a class after a dotted string.
     */
    private const CONTRACT_EVENTS = [
        TelemetryExcursionDetected::class,
        EmergencyFuelIssued::class,
        VehicleStatusChanged::class,
    ];

    /**
     * Choose where drivers are read from.
     *
     * 'auto' detects the CRM: if the customer/vendor people registers exist,
     * STOS reads them live; otherwise it falls back to its own register. That
     * single binding is what makes the module run both integrated and
     * standalone without a second codebase or a build flag.
     */
    public function register(): void
    {
        // C-05 — Fleet answers the dispatch seam. Replaces
        // PendingFleetResourceGateway, whose TODO was addressed to Person 2.
        // Bound only when the Transport module is installed, so the Fleet
        // module still boots standalone.
        if (interface_exists(\App\Services\Transport\Contracts\FleetResourceGateway::class)) {
            $this->app->bind(
                \App\Services\Transport\Contracts\FleetResourceGateway::class,
                TransportFleetResourceGateway::class
            );
        }

        $this->app->singleton(DriverDirectory::class, function () {
            $mode = config('stos.directory.driver', 'auto');

            if ($mode === 'standalone') {
                return new StandaloneDriverDirectory();
            }

            if ($mode === 'crm') {
                return new CrmDriverDirectory();
            }

            if ($mode === 'both') {
                return new CompositeDriverDirectory(new CrmDriverDirectory(), new StandaloneDriverDirectory());
            }

            // ── `auto`, corrected 2026-09-23 — D-134 ─────────────────────
            // This used to choose ONE, on the assumption that the two sources
            // are alternatives. The D-62 move made them simultaneous: it put
            // the drivers it found into `stos_drivers` and pointed their
            // profiles at it, correctly, because there was no CRM person to
            // point at. A CRM installation can now hold people in both.
            //
            // So `auto` asks a second question. Choosing the CRM alone hid the
            // migrated drivers from allocation entirely; choosing standalone
            // alone would have hidden every CRM-sourced one instead.
            $crmPresent = Schema::hasTable('tpv_workers')
                || Schema::hasTable('purchase_workers')
                || Schema::hasTable('client_contacts');

            // Deliberately NOT conditioned on `stos_drivers` having rows.
            // This binding is a singleton, so any data-dependent choice is
            // frozen at whatever the table held the first time it resolved —
            // fine in a request that boots fresh, wrong in a queue worker, and
            // wrong in a test that creates its drivers afterwards. It also made
            // the composite's presence depend on the order things happened in,
            // which is not a property anybody wants to debug.
            //
            // The composite over an empty local register simply returns the CRM
            // list, so there is nothing to gain by asking.
            return $crmPresent
                ? new CompositeDriverDirectory(new CrmDriverDirectory(), new StandaloneDriverDirectory())
                : new StandaloneDriverDirectory();
        });
    }

    public function boot(): void
    {
        // D-135 — a driver's name, filled in wherever a profile is read.
        //
        // `driver_profiles` has no `name` column; the name lives in the
        // directory. After the repoint every `$trip->driver->name` in the
        // codebase silently became null, and the allocation panel rendered a
        // blank where a driver WAS assigned — indistinguishable from nothing
        // being assigned.
        //
        // Hooked here rather than patched at each reader, because it was
        // already fixed once for two screens and four more were still reading
        // a name. This is our provider; P2's model is untouched.
        DriverProfile::retrieved(function (DriverProfile $profile) {
            app(DriverNaming::class)->attach($profile);
        });


        foreach (self::LISTENERS as $event => $listeners) {
            foreach ($listeners as $listener) {
                Event::listen($event, $listener);
            }
        }

        foreach (self::CONTRACT_EVENTS as $class) {
            Event::listen($class, function ($event) use ($class) {
                // Dispatching a DIFFERENT name, so this cannot recurse.
                Event::dispatch($class::NAME, [$event->toPayload()]);
            });
        }

        // Every change of a vehicle's availability is announced, whichever code
        // path made it — see VehicleStatusObserver.
        Vehicle::observe(VehicleStatusObserver::class);
    }
}
