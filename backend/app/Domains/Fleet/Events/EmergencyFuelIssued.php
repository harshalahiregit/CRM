<?php

namespace App\Domains\Fleet\Events;

use App\Domains\Fleet\Models\Vehicle;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * STOS-COST — `fuel.emergency_issued`.
 *
 * A driver fuelled off-network or off-card. Developer 3's billing engine
 * listens for this to decide whether the cost reaches a customer invoice; the
 * fleet side only states the fact and who it is chargeable to.
 *
 * `customerRecoverable` is deliberately NULLABLE: "nobody has decided yet" is a
 * real state at the moment of entry, and defaulting it to false would quietly
 * write off money the customer owes.
 */
class EmergencyFuelIssued
{
    use Dispatchable;
    use SerializesModels;

    /** The name Developer 3 listens for (STOS-TM-001). */
    public const NAME = 'fuel.emergency_issued';

    public function __construct(
        public readonly Vehicle $vehicle,
        public readonly int $fuelTransactionId,
        public readonly float $amount,
        public readonly ?bool $customerRecoverable,
        public readonly ?string $reason,
    ) {
    }

    /** The cross-team payload. Plain scalars — no models cross the boundary. */
    public function toPayload(): array
    {
        return [
            'event'                => self::NAME,
            'company_id'           => (int) $this->vehicle->company_id,
            'vehicle_id'           => (int) $this->vehicle->id,
            'registration_number'  => (string) $this->vehicle->registration_number,
            'fuel_transaction_id'  => $this->fuelTransactionId,
            'amount'               => $this->amount,
            'customer_recoverable' => $this->customerRecoverable,
            'reason'               => $this->reason,
        ];
    }
}
