<?php

namespace App\Services\Transport;

use App\Events\Transport\CollectionRecorded;
use App\Exceptions\BusinessException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TripBill;
use App\Models\Transport\TripCollection;
use App\Models\User;
use App\Support\Transport\CollectionStatus;
use App\Support\Transport\TripStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SNG-TRN-016 — receivable tracking.
 *
 * The acceptance criterion is four nouns: **"Due dates, blockers, follow-up and
 * audit trail."** Each has a method here, and the fourth needs none of its own —
 * every act on this record goes through RecordsTransportAudit, so a receipt, a
 * blocker and a chase all land in one timeline with the actor and the reason.
 *
 * ── TRACKING, NOT POSTING ────────────────────────────────────────────────
 * EVT-011's producer is "Accounts/Collections" and its payload is `receipt_id,
 * invoice_id, amount` — accounting identifiers this module does not create.
 * CTR-014's note on API-011 is "Posting event generated". So recording a
 * receipt here moves a tracked balance and emits a trigger; the posting is
 * Accounts' work through PostingService. FORBID-002, LOCK-004. See D-61.
 *
 * ── STT-011 ──────────────────────────────────────────────────────────────
 *   billed -> collection_pending | trigger "Invoice posted" | actor Collections
 *           | guard "Receivable exists" | effect "Create collection task"
 *           | audited | LOCKED
 *
 * `open()` is that effect. Its source state `billed` is set by STT-010, whose
 * actor is **Accounts** — so like POD verification before it, this edge is
 * built and currently unreachable until the other module acts. Recorded as C-11
 * rather than left as a surprise.
 *
 * ── THE STATUS IS DERIVED, NEVER SET ─────────────────────────────────────
 * recompute() is the only thing that writes it, from amount_due against
 * amount_received, with bccomp rather than `<=`. IDX-009 indexes that column
 * for the ageing report, so a status disagreeing with its own arithmetic is a
 * wrong REPORT, not merely a wrong screen.
 */
class TripCollectionService
{
    /* ── Reading ──────────────────────────────────────────────────────── */

    public function find(int $id, int $tenantId): TripCollection
    {
        $collection = TripCollection::forTenant($tenantId)->find($id);

        if (! $collection) {
            throw new ResourceNotFoundException('Collection');
        }

        return $collection;
    }

    public function forTrip(int $tripId, int $tenantId): ?TripCollection
    {
        return TripCollection::forTenant($tenantId)->forTrip($tripId)->first();
    }

    /**
     * The ageing report — IDX-009's reason for existing.
     *
     * Buckets are the conventional 0/30/60/90, computed in PHP from each row's
     * own due date rather than by a database DATEDIFF, because sqlite and MySQL
     * spell that differently and D-51 has the suite running on one while
     * production runs the other.
     *
     * @return array{buckets: array<string, string>, total: string, rows: \Illuminate\Support\Collection}
     */
    public function ageing(int $tenantId, ?string $asOf = null): array
    {
        $asOf    = $asOf ?? now()->toDateString();
        $rows    = TripCollection::forTenant($tenantId)->outstanding()->get();
        $buckets = ['current' => '0.00', '1_30' => '0.00', '31_60' => '0.00', '61_90' => '0.00', 'over_90' => '0.00'];
        $total   = '0.00';

        foreach ($rows as $row) {
            $outstanding = $row->outstanding;
            $total       = bcadd($total, $outstanding, 2);
            $days        = $row->daysOverdue($asOf);

            $key = match (true) {
                $days === null, $days <= 0 => 'current',
                $days <= 30                => '1_30',
                $days <= 60                => '31_60',
                $days <= 90                => '61_90',
                default                    => 'over_90',
            };

            $buckets[$key] = bcadd($buckets[$key], $outstanding, 2);
        }

        return ['buckets' => $buckets, 'total' => $total, 'rows' => $rows];
    }

    /** What somebody should chase today — the "follow-up" half of the criterion. */
    public function followUpQueue(int $tenantId, ?string $asOf = null)
    {
        return TripCollection::forTenant($tenantId)
            ->dueForFollowUp($asOf)
            ->orderBy('next_follow_up_on')
            ->get();
    }

    /* ── Writing ──────────────────────────────────────────────────────── */

    /**
     * STT-011's "Create collection task" — open the receivable for a trip.
     *
     * Idempotent: a trip has one receivable (unique on tenant_id, trip_id), so
     * opening twice returns the existing row. "Duplicate callbacks" is a listed
     * edge case, and Accounts posting an invoice twice must not create two.
     */
    public function open(
        TransportTrip $trip,
        int $tenantId,
        ?string $dueDate = null,
        ?User $actor = null,
    ): TripCollection {
        if ((int) $trip->tenant_id !== $tenantId) {
            throw new ResourceNotFoundException('Trip');
        }

        $existing = $this->forTrip($trip->id, $tenantId);
        if ($existing) {
            return $existing;
        }

        // "Receivable exists" — STT-011's guard. There is nothing to collect
        // until Transport has ruled the trip billable and frozen an amount.
        $bill = TripBill::forTenant($tenantId)->forTrip($trip->id)->first();

        if (! $bill) {
            throw new BusinessException(
                'This trip has no prepared billing, so there is nothing to collect yet.'
            );
        }

        try {
            $collection = DB::transaction(function () use ($trip, $bill, $tenantId, $dueDate, $actor) {
                $collection = TripCollection::create([
                    'tenant_id'   => $tenantId,
                    'trip_id'     => $trip->id,
                    'bill_id'     => $bill->id,
                    'amount_due'  => (string) $bill->billable_amount,
                    'currency'    => $bill->currency ?? 'INR',
                    'due_date'    => $dueDate,
                    'created_by'  => $actor?->id,
                ]);

                $collection->audit('transport.collection.opened', $actor, new: [
                    'trip_id' => $trip->id, 'amount_due' => (string) $collection->amount_due,
                    'due_date' => $dueDate,
                ]);

                // STT-011. Checked against the machine rather than forced, so an
                // edge that is ever removed fails loudly instead of silently.
                if (TripStatus::canTransition((string) $trip->status, TripStatus::COLLECTION_PENDING)) {
                    $from = (string) $trip->status;
                    $trip->forceFill([
                        'status' => TripStatus::COLLECTION_PENDING, 'updated_by' => $actor?->id,
                    ])->save();
                    $trip->auditTransition(
                        'transport.trip.collection_pending', $from, TripStatus::COLLECTION_PENDING, $actor
                    );
                }

                return $collection;
            });
        } catch (QueryException $e) {
            $winner = $this->forTrip($trip->id, $tenantId);
            if ($winner) {
                return $winner;
            }
            throw $e;
        }

        Log::channel('transport')->info('Collection opened', [
            'collection_id' => $collection->id, 'trip_id' => $trip->id,
            'amount_due' => (string) $collection->amount_due, 'tenant_id' => $tenantId,
        ]);

        return $collection->fresh();
    }

    /**
     * API-011 — record a receipt against the receivable.
     *
     * Moves the tracked balance and emits the trigger. Writes no ledger line.
     */
    public function record(
        TripCollection $collection,
        string $amount,
        int $tenantId,
        ?User $actor = null,
        ?string $reference = null,
    ): TripCollection {
        $this->assertOwn($collection, $tenantId);

        $amount = $this->assertAmount($amount);

        // A receipt may not exceed what is owed. Over-collection is a credit
        // note or a refund, and both are Accounts' decisions — silently
        // accepting it here would put a negative balance into the ageing report
        // and make the total meaningless.
        if (bccomp($amount, $collection->outstanding, 2) === 1) {
            throw new BusinessException(
                'That is more than the '.$collection->outstanding.' still outstanding. '
                .'An overpayment is handled by Accounts, not here.'
            );
        }

        $received = bcadd((string) $collection->amount_received, $amount, 2);

        DB::transaction(function () use ($collection, $received, $amount, $actor, $reference) {
            $collection->forceFill([
                'amount_received' => $received,
                'updated_by'      => $actor?->id,
            ])->save();

            $this->recompute($collection, $actor);

            $collection->audit('transport.collection.received', $actor, new: [
                'amount'    => $amount,
                'received'  => $received,
                'reference' => $reference,
            ]);
        });

        $collection->refresh();

        // EVT-011's trigger half. See the event class for why the registry
        // payload cannot be filled by this module.
        CollectionRecorded::dispatch($collection, $amount);

        Log::channel('transport')->info('Collection receipt recorded', [
            'collection_id' => $collection->id, 'trip_id' => $collection->trip_id,
            'amount' => $amount, 'status' => $collection->status, 'tenant_id' => $tenantId,
        ]);

        return $collection;
    }

    /** "blockers" — why this receivable is not moving. */
    public function block(TripCollection $collection, string $reason, int $tenantId, ?User $actor = null): TripCollection
    {
        $this->assertOwn($collection, $tenantId);

        if (trim($reason) === '') {
            throw new BusinessException('A reason is required to flag a blocker.');
        }

        $collection->forceFill([
            'blocker_reason' => trim($reason),
            'blocked_at'     => now(),
            'updated_by'     => $actor?->id,
        ])->save();

        $collection->audit('transport.collection.blocked', $actor, new: ['reason' => trim($reason)]);

        return $collection->fresh();
    }

    public function clearBlocker(TripCollection $collection, int $tenantId, ?User $actor = null): TripCollection
    {
        $this->assertOwn($collection, $tenantId);

        $was = $collection->blocker_reason;

        $collection->forceFill([
            'blocker_reason' => null, 'blocked_at' => null, 'updated_by' => $actor?->id,
        ])->save();

        $collection->audit('transport.collection.unblocked', $actor, old: ['reason' => $was]);

        return $collection->fresh();
    }

    /** "follow-up" — record that somebody chased, and when they will again. */
    public function followUp(
        TripCollection $collection,
        int $tenantId,
        ?string $nextOn = null,
        ?string $note = null,
        ?User $actor = null,
    ): TripCollection {
        $this->assertOwn($collection, $tenantId);

        $collection->forceFill([
            'last_followed_up_at' => now(),
            'last_followed_up_by' => $actor?->id,
            'next_follow_up_on'   => $nextOn,
            'updated_by'          => $actor?->id,
        ])->save();

        // The audit trail IS the follow-up history — the fourth noun in the
        // acceptance criterion, served by the trail every other record uses
        // rather than by a private table that would split the story in two.
        $collection->audit('transport.collection.followed_up', $actor, new: [
            'note' => $note, 'next_follow_up_on' => $nextOn,
        ]);

        return $collection->fresh();
    }

    /* ── Rules ────────────────────────────────────────────────────────── */

    /**
     * Derive the status from the arithmetic. The only writer of that column.
     */
    private function recompute(TripCollection $collection, ?User $actor): void
    {
        $status = CollectionStatus::fromAmounts(
            (string) $collection->amount_due,
            (string) $collection->amount_received,
        );

        if ($status === (string) $collection->status) {
            return;
        }

        $from = (string) $collection->status;

        $collection->forceFill([
            'status'     => $status,
            'settled_at' => $status === CollectionStatus::SETTLED ? now() : null,
        ])->save();

        $collection->auditTransition('transport.collection.status', $from, $status, $actor);
    }

    private function assertOwn(TripCollection $collection, int $tenantId): void
    {
        if ((int) $collection->tenant_id !== $tenantId) {
            throw new ResourceNotFoundException('Collection');
        }
    }

    private function assertAmount(string $raw): string
    {
        if ($raw === '' || ! is_numeric($raw)) {
            throw new BusinessException('The amount must be a number.');
        }

        // CTR-014: `amount_received` DECIMAL(18,2), required, validation ">0".
        if (bccomp($raw, '0.00', 2) <= 0) {
            throw new BusinessException('A receipt must be greater than zero.');
        }

        return bcadd($raw, '0.00', 2);
    }
}
