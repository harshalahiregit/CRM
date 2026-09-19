<?php

namespace App\Domains\Fleet\Services;

use App\Domains\Fleet\Models\Vehicle;
use Illuminate\Support\Carbon;

/**
 * STOS-CMP — the pre-dispatch gate (Stage 4).
 *
 * The five statutory dates decide whether a vehicle may legally move, and this
 * is the ONLY place that decision is made. `compliance_status` is therefore a
 * DERIVED column, never typed by a person:
 *
 *   expired   — at least one document is out of date  ⇒ dispatch blocked
 *   expiring  — at least one falls due inside the warning window
 *   compliant — everything in date
 *   blocked   — a manual hold, which no recompute may clear
 *
 * "Control before reporting" (golden rule 1) is why this exists as a scheduled
 * sweep as well as an on-save recompute: an insurance certificate expires while
 * everybody sleeps, and a vehicle that was compliant last night must be blocked
 * this morning without anyone having touched the record.
 */
class ComplianceService
{
    /** How far ahead a document counts as "expiring". */
    public const WARNING_DAYS = 30;

    /*
     | A document is valid THROUGH its expiry date, and expired the day after.
     |
     | This is not a rounding choice, it is the legal reading: an insurance
     | certificate or PUC dated the 30th covers the vehicle on the 30th. Blocking
     | on the date itself would ground a legally compliant truck for a day, every
     | time — and this gate stops dispatch, so a wrong call here costs a load.
     */

    /**
     * The verdict for one vehicle, with the evidence.
     *
     * @return array{status: string, documents: array, expired: array, expiring: array}
     */
    public function evaluate(Vehicle $vehicle): array
    {
        $today = now()->startOfDay();
        $documents = [];
        $expired = [];
        $expiring = [];

        foreach (Vehicle::EXPIRY_DOCUMENTS as $field => $label) {
            $date = $vehicle->{$field};

            if (! $date) {
                // A missing date is NOT a pass. It is unknown, and the tab says
                // so — but it does not block dispatch on its own, because a
                // fleet migrating in from spreadsheets would be grounded whole.
                $documents[] = [
                    'field' => $field, 'label' => $label, 'date' => null,
                    'days_left' => null, 'state' => 'unknown',
                ];

                continue;
            }

            $daysLeft = (int) $today->diffInDays(Carbon::parse($date)->startOfDay(), false);
            $state = $daysLeft < 0 ? 'expired' : ($daysLeft <= self::WARNING_DAYS ? 'expiring' : 'valid');

            $documents[] = [
                'field' => $field, 'label' => $label,
                'date' => Carbon::parse($date)->toDateString(),
                'days_left' => $daysLeft, 'state' => $state,
            ];

            if ($state === 'expired') {
                $expired[] = $label;
            } elseif ($state === 'expiring') {
                $expiring[] = $label;
            }
        }

        return [
            'status'    => $this->verdict($vehicle, $expired, $expiring),
            'documents' => $documents,
            'expired'   => $expired,
            'expiring'  => $expiring,
        ];
    }

    /**
     * Write the derived verdict back, and report whether it moved.
     *
     * The write goes through the model so the status observer fires and
     * Developers 1 and 3 hear about a vehicle that has just been grounded.
     */
    public function refresh(Vehicle $vehicle): bool
    {
        $verdict = $this->evaluate($vehicle)['status'];

        if ($vehicle->compliance_status === $verdict) {
            return false;
        }

        $vehicle->compliance_status = $verdict;
        $vehicle->save();

        return true;
    }

    /**
     * The nightly sweep. Returns how many vehicles changed verdict.
     *
     * Scoped per company by the caller; a null company means every workspace,
     * which is what the scheduled command wants.
     */
    public function refreshAll(?int $companyId = null): array
    {
        $changed = [];

        Vehicle::query()
            ->when($companyId, fn ($q) => $q->forCompany($companyId))
            ->whereIn('status', [Vehicle::STATUS_AVAILABLE, Vehicle::STATUS_IDLE, Vehicle::STATUS_UNDER_MAINTENANCE, Vehicle::STATUS_COMPLIANCE_BLOCKED])
            ->chunkById(200, function ($vehicles) use (&$changed) {
                foreach ($vehicles as $vehicle) {
                    $before = $vehicle->compliance_status;

                    if ($this->refresh($vehicle)) {
                        $changed[] = [
                            'vehicle_id' => $vehicle->id,
                            'registration_number' => $vehicle->registration_number,
                            'from' => $before,
                            'to' => $vehicle->compliance_status,
                        ];
                    }
                }
            });

        return $changed;
    }

    /** May this vehicle legally be dispatched right now? */
    public function blocksDispatch(Vehicle $vehicle): bool
    {
        return in_array($this->evaluate($vehicle)['status'], ['expired', 'blocked'], true);
    }

    private function verdict(Vehicle $vehicle, array $expired, array $expiring): string
    {
        // A human hold outranks every date. Somebody blocked this vehicle for a
        // reason no expiry column knows about, and a nightly job must never
        // quietly overrule them.
        if ($vehicle->compliance_hold) {
            return 'blocked';
        }

        if ($expired !== []) {
            return 'expired';
        }

        return $expiring !== [] ? 'expiring' : 'compliant';
    }
}
