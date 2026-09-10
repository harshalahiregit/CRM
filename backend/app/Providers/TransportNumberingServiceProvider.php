<?php

namespace App\Providers;

use App\Support\Numbering\DocumentTypeRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Registers Transport's document types with the central Document Numbering
 * Engine (SNG-TRN-006 / SNG-TRN-007).
 *
 * Done here rather than by editing DocumentTypeRegistry::TYPES because that
 * class explicitly supports it — "Modules may also register types at runtime via
 * register() … without touching this file" — which keeps Transport out of a file
 * three other modules also edit. Same approach SalesNumberingServiceProvider
 * takes, for the same reason.
 *
 * The engine is the ONLY allocator: its interface states "Every module MUST
 * allocate through this contract — no module may implement its own numbering."
 * That also keeps Transport away from the hand-rolled MAX(CAST(...)) sequence
 * pattern, which is where the AS INTEGER / AS SIGNED defect lived in HR and Sales.
 *
 * Definition tuple: [label, module, format, prefix, minimum_digits, reset_rule]
 *
 * Formats follow the examples in the approved documents: STOS-DB §16 shows
 * "TO-2026-000182" and the prototype script shows "TO-2026-000125", hence TO with
 * six digits. No trip-number example appears anywhere, so the trip type mirrors
 * the order's shape — the one formatting choice here, and it is a presentation
 * decision, not a rule. BR-P0-001's actual requirement is uniqueness within
 * company/YEAR, which the 'yearly' reset rule is what delivers.
 */
class TransportNumberingServiceProvider extends ServiceProvider
{
    /**
     * Bind the Fleet boundary.
     *
     * TODO(Developer A / Fleet): swap PendingFleetResourceGateway for the real
     * reserve/release service. BRW-050 needs a dispatched trip to set
     * Vehicle = In Operation and Driver = On Trip, but transport_vehicles and
     * transport_drivers belong to Fleet and the owner ruled on 2026-09-10 that
     * Trip side must not write them. This one line is the whole swap — no
     * dispatch code changes.
     */
    public function register(): void
    {
        $this->app->bind(
            \App\Services\Transport\Contracts\FleetResourceGateway::class,
            \App\Services\Transport\PendingFleetResourceGateway::class,
        );
    }

    public function boot(): void
    {
        // TO-2026-000125  (STOS-DB §16, prototype video Scene 3)
        DocumentTypeRegistry::register('transport_order', [
            'Transport Order', 'Transport', '{PREFIX}-{YYYY}-{NEXT}', 'TO', 6, 'yearly',
        ]);

        // TRP-2026-000001 — BR-P0-001: unique within company/year.
        DocumentTypeRegistry::register('transport_trip', [
            'Transport Trip', 'Transport', '{PREFIX}-{YYYY}-{NEXT}', 'TRP', 6, 'yearly',
        ]);
    }
}
