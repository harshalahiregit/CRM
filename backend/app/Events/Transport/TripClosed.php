<?php

namespace App\Events\Transport;

use App\Models\Transport\TransportTrip;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * EVT-012 TripClosed — STT-012's side effect.
 *
 * Registry row, verbatim:
 *   Producer         TripEngine
 *   Payload Core     trip_id, closure_timestamp
 *   Idempotency Key  trip_id+close_version
 *   Consumers        ProfitEngine, ControlRoom
 *   Financial Impact Potential
 *   Audit            Yes
 *   Status           LOCKED
 *
 * ── EMITTED THOUGH NEITHER CONSUMER EXISTS ────────────────────────────────
 * ProfitEngine is SNG-TRN-018 and `trip_profit_snapshots` is not a table.
 * ControlRoom is API-012 and is not built. Same published-seam pattern as
 * EVT-001, EVT-002 and EVT-004: emit-only, no outbox, no new mechanism.
 *
 * The event is emitted ANYWAY, and that is deliberate. STT-012's side effect is
 * "Snapshot profit"; dropping the event because the snapshotter is late would
 * make the gap invisible on the day it arrives, and the first closed trip after
 * that would be the first one ProfitEngine ever heard about. A seam that has
 * always been firing is a seam somebody can subscribe to.
 *
 * ── THE IDEMPOTENCY KEY CANNOT BE HONOURED AS SPECIFIED — D-107 ──────────
 * The registry keys this on `trip_id+close_version`. There is no
 * `close_version` column, no versions table, and no field registry entry that
 * defines one. This is the same shape as D-65, where EVT-004's key named an
 * `approval_id` with no approvals table and the ruling was: DO NOT INVENT ONE.
 *
 * Nothing is substituted. Not a counter, not a uuid, not the audit row id.
 * `closed` is SM-TRP's only TERMINAL state, so the state machine itself is the
 * idempotency — a second close cannot transition, and there is therefore never
 * a second event to de-duplicate. `closed_at` stands in as the discriminator
 * because it is the fact that actually exists.
 *
 * ── WHAT CLOSING DID NOT PROVE ───────────────────────────────────────────
 * STT-012's precondition is "Settlement/POD/billing controls pass". Two of the
 * five controls CANNOT BE EVALUATED — supplier settlement has no table
 * (SNG-TRN-017) and the exception register has no model (SNG-TRN-013 stopped at
 * the schema). A consumer must not read this event as evidence that a trip had
 * no unresolved critical exception, only that the controls which exist passed.
 * `controls()` below says exactly which ones ran. See ClosureScope::CONTROLS.
 */
class TripClosed
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string,string>  $controls  control key => 'passed'|'not_checked'
     */
    public function __construct(
        public TransportTrip $trip,
        public array $controls = [],
    ) {
    }

    /** EVT-012's LOCKED Payload Core, and nothing beyond it. */
    public function payload(): array
    {
        return [
            'trip_id'           => (int) $this->trip->id,
            'closure_timestamp' => $this->trip->closed_at?->toIso8601String(),
        ];
    }

    /**
     * EVT-012 Idempotency Key — specified as "trip_id+close_version".
     *
     * `close_version` has no column and no table (D-107), so `closed_at` stands
     * in. It is the fact that exists, and `closed` being terminal means it can
     * never move.
     */
    public function idempotencyKey(): string
    {
        return $this->trip->id.'+'.($this->trip->closed_at?->getTimestamp() ?? 0);
    }

    /**
     * Which closure controls actually ran, alongside the payload rather than
     * inside it — the registry fixes the payload and this is not in it.
     *
     * A consumer that treats closure as proof of a clean trip needs to know
     * that two of the five controls could not be evaluated at all.
     *
     * @return array<string,string>
     */
    public function controls(): array
    {
        return $this->controls;
    }

    /** Tenancy travels with the event; a listener must not have to infer it. */
    public function tenantId(): int
    {
        return (int) $this->trip->tenant_id;
    }
}
