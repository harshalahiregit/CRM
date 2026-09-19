<?php

namespace App\Events\Transport;

use App\Models\Transport\TripException;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * EVT-008 TripExceptionRaised — the exception register's only event.
 *
 * Registry row, verbatim:
 *   Producer         ExceptionEngine
 *   Payload Core     exception_id, trip_id, severity
 *   Idempotency Key  exception_id+version
 *   Consumers        Notifications, ControlRoom
 *   Financial Impact No
 *   Audit            Yes
 *   Status           LOCKED
 *
 * ── THE IDEMPOTENCY KEY IS HONOURABLE HERE, UNUSUALLY ───────────────────
 * Every other event in this module keys on a field that does not exist —
 * EVT-004 on an `approval_id` with no approvals table (D-65), EVT-012 on a
 * `close_version` with no versions table (D-107). `exception_id+version` needs
 * only the row's own identity and a monotonic number, and `updated_at` supplies
 * the second honestly: an exception that has not changed emits the same key.
 *
 * Neither consumer subscribes yet. Notifications is SNG-TRN-021 and ControlRoom
 * is API-012, and both are unbuilt — same published-seam pattern as every other
 * event here: emit-only, no outbox, no new mechanism.
 *
 * ── WHAT THIS EVENT DOES NOT CARRY ──────────────────────────────────────
 * OPS §87 requires every exception to hold a financial impact and a customer
 * impact. No formula exists anywhere in the package and no cost model exists
 * until SNG-TRN-012/018 (D-32), so both fields are absent rather than zero. A
 * consumer must not read the absence as "no impact".
 */
class TripExceptionRaised
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public TripException $exception,
    ) {
    }

    /** EVT-008's LOCKED Payload Core, and nothing beyond it. */
    public function payload(): array
    {
        return [
            'exception_id' => (int) $this->exception->id,
            'trip_id'      => $this->exception->trip_id === null ? null : (int) $this->exception->trip_id,
            'severity'     => (string) $this->exception->severity,
        ];
    }

    /** `exception_id+version`; `updated_at` is the version that exists. */
    public function idempotencyKey(): string
    {
        return $this->exception->id.'+'.($this->exception->updated_at?->getTimestamp() ?? 0);
    }

    public function tenantId(): int
    {
        return (int) $this->exception->tenant_id;
    }
}
