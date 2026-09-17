<?php

namespace App\Domains\Integration\Events;

use App\Domains\Fleet\Models\Vehicle;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * STOS-INT — the cold chain is breaking on this vehicle.
 *
 * Raised by TelemetryIngestionService when a ping shows the genset OFF while
 * the body is warmer than the configured threshold. It is a statement of fact
 * about one reading, not an instruction: whoever listens decides whether that
 * means an alert, a task, or a line on a report.
 *
 * `recordedAt` is the DEVICE's clock and `wasLive` says whether this ping was
 * the newest we hold for the vehicle. A buffered unit can replay an excursion
 * from three hours ago, and a listener that pages someone must be able to tell
 * that apart from one happening now.
 */
class TelemetryExcursionDetected
{
    use Dispatchable;
    use SerializesModels;

    /**
     * The name Developer 3 listens for (STOS-TM-001). StosServiceProvider
     * re-broadcasts every dispatch of this class under this string, so a
     * consumer can subscribe by the documented contract name without depending
     * on our class namespace.
     */
    public const NAME = 'telemetry.temperature_excursion.detected';

    public function __construct(
        public readonly Vehicle $vehicle,
        public readonly float $temperature,
        public readonly float $threshold,
        public readonly ?string $generatorStatus,
        public readonly string $recordedAt,
        public readonly bool $wasLive,
        public readonly int $telemetryRecordId,
    ) {
    }

    /** The cross-team payload. Plain scalars — a consumer must not need our models. */
    public function toPayload(): array
    {
        return [
            'event'               => self::NAME,
            'company_id'          => (int) $this->vehicle->company_id,
            'vehicle_id'          => (int) $this->vehicle->id,
            'registration_number' => (string) $this->vehicle->registration_number,
            'temperature'         => $this->temperature,
            'threshold'           => $this->threshold,
            'degrees_over'        => $this->degreesOver(),
            'generator_status'    => $this->generatorStatus,
            'recorded_at'         => $this->recordedAt,
            'was_live'            => $this->wasLive,
            'telemetry_record_id' => $this->telemetryRecordId,
        ];
    }

    /** How far above the limit, in degrees. Always positive. */
    public function degreesOver(): float
    {
        return round($this->temperature - $this->threshold, 2);
    }
}
