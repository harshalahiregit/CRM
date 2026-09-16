<?php

namespace App\Domains\Integration\Listeners;

use App\Domains\Integration\Events\TelemetryExcursionDetected;
use Illuminate\Support\Facades\Log;

/**
 * The first listener on TelemetryExcursionDetected: write it down.
 *
 * Deliberately the whole of it. Notifying a customer, opening a claim or
 * blocking a POD are decisions that need Dispatch's trip context (Developer 1),
 * and inventing them here would put alerting rules in the ingestion path.
 * A durable, timestamped record is what the next sprint builds on — and it is
 * what makes the event observably fire during manual testing.
 *
 * Synchronous on purpose: ingestion is already inside the request, and queueing
 * a log line would make an excursion arrive after the response that caused it.
 */
class LogTemperatureExcursion
{
    public function handle(TelemetryExcursionDetected $event): void
    {
        Log::channel('stos')->warning('Reefer temperature excursion', [
            'company_id'          => $event->vehicle->company_id,
            'vehicle_id'          => $event->vehicle->id,
            'registration_number' => $event->vehicle->registration_number,
            'temperature'         => $event->temperature,
            'threshold'           => $event->threshold,
            'degrees_over'        => $event->degreesOver(),
            'generator_status'    => $event->generatorStatus,
            'recorded_at'         => $event->recordedAt,
            // false = a buffered unit replaying an older excursion, not one
            // happening right now.
            'live'                => $event->wasLive,
            'telemetry_record_id' => $event->telemetryRecordId,
        ]);
    }
}
