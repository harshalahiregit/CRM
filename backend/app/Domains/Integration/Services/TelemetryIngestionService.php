<?php

namespace App\Domains\Integration\Services;

use App\Domains\Fleet\Models\TelemetryRecord;
use App\Domains\Fleet\Models\Vehicle;
use App\Domains\Fleet\Models\VehicleLiveStatus;
use App\Domains\Integration\Events\TelemetryExcursionDetected;
use App\Exceptions\BusinessException;
use Illuminate\Support\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * STOS-INT — one ping in, both telemetry tiers updated (golden rule 3).
 *
 * The whole ingestion rule set lives here; the controller only hands over
 * validated data (golden rule 4).
 *
 * Two writes, and they are not symmetrical:
 *   • telemetry_records — ALWAYS appended. History is the source of truth and
 *     nothing is allowed to drop a reading.
 *   • vehicle_live_status — updated ONLY when this ping is the newest we hold.
 *     A unit that buffered through a tunnel replays an hour of old positions in
 *     seconds; letting those overwrite the live row would march the truck
 *     backwards across the map and end with the live position an hour stale.
 */
class TelemetryIngestionService
{
    /**
     * @param  int|null  $companyId  from the device's own credential (T-07), never
     *                               from the payload — a box does not get to say
     *                               which company's truck it is reporting for.
     */
    public function ingest(array $payload, ?int $companyId = null): array
    {
        $vehicle = $this->resolveVehicle((string) $payload['device_id'], $companyId);
        $recordedAt = Carbon::parse($payload['recorded_at']);

        $reading = [
            'latitude'         => $payload['latitude']  ?? null,
            'longitude'        => $payload['longitude'] ?? null,
            'speed'            => $payload['speed']     ?? null,
            'ignition'         => array_key_exists('ignition', $payload) ? (bool) $payload['ignition'] : null,
            'generator_status' => $payload['generator_status'] ?? null,
            'temperature'      => $payload['temperature'] ?? null,
        ];

        // T-12 — a unit leaving a tunnel replays its buffer and a flaky link
        // makes it retry, so the same ping arrives more than once. One device
        // has one clock: the same instant is the same reading, not a new fact.
        $existing = TelemetryRecord::where('company_id', $vehicle->company_id)
            ->where('device_id', (string) $payload['device_id'])
            ->where('recorded_at', $recordedAt)
            ->first();

        if ($existing) {
            // Answered as a success, not a conflict. A device that is told 409
            // by a retry it could not avoid will either retry forever or drop
            // the buffer, and both lose trail we already hold.
            return [
                'vehicle_id'          => $vehicle->id,
                'registration_number' => $vehicle->registration_number,
                'telemetry_record_id' => $existing->id,
                'live_status_updated' => false,
                'excursion_detected'  => false,
                'duplicate'           => true,
            ];
        }

        // The live row as it stands BEFORE this ping. The timeline publishes on
        // CHANGE — genset stopped, temperature moved — so it needs the previous
        // value, and after the transaction below it is gone.
        $previous = VehicleLiveStatus::forCompany($vehicle->company_id)
            ->where('vehicle_id', $vehicle->id)->first();

        // One transaction: a ping is either fully recorded or not at all. A
        // history row without its live update would leave the map lying.
        [$record, $liveUpdated, $duplicate] = DB::transaction(function () use ($vehicle, $payload, $recordedAt, $reading) {
            try {
                $record = TelemetryRecord::create([
                    ...$reading,
                    'company_id'  => $vehicle->company_id,
                    'vehicle_id'  => $vehicle->id,
                    'device_id'   => (string) $payload['device_id'],
                    'recorded_at' => $recordedAt,
                ]);
            } catch (QueryException $e) {
                // Two copies of the same ping in flight at once. The check
                // above cannot settle that race; the unique index can, and
                // this is the losing side of it.
                if (! $this->isDuplicateKey($e)) {
                    throw $e;
                }

                $record = TelemetryRecord::where('company_id', $vehicle->company_id)
                    ->where('device_id', (string) $payload['device_id'])
                    ->where('recorded_at', $recordedAt)
                    ->firstOrFail();

                return [$record, false, true];
            }

            $liveUpdated = $this->refreshLiveStatus($vehicle, $recordedAt, $reading);

            return [$record, $liveUpdated, false];
        });

        if ($duplicate) {
            return [
                'vehicle_id'          => $vehicle->id,
                'registration_number' => $vehicle->registration_number,
                'telemetry_record_id' => $record->id,
                'live_status_updated' => false,
                'excursion_detected'  => false,
                'duplicate'           => true,
            ];
        }

        $excursion = $this->checkExcursion($vehicle, $reading, $recordedAt, $liveUpdated, $record->id);

        // The shared trip timeline (Person 1's `trip_events`). Only when this
        // ping belongs to a live trip, and only on change — a position every
        // two minutes would bury the four events somebody actually reads a
        // journey for. The full trail stays in `telemetry_records`.
        $timeline = app(\App\Domains\Fleet\Integration\TripTimelinePublisher::class);
        $timeline->publish($vehicle, $reading, $recordedAt, $previous);

        if ($excursion) {
            $timeline->publishExcursion($vehicle, $reading, $recordedAt);
        }

        return [
            'vehicle_id'          => $vehicle->id,
            'registration_number' => $vehicle->registration_number,
            'telemetry_record_id' => $record->id,
            // false means this ping was older than the live row — recorded in
            // history, deliberately not promoted to "now".
            'live_status_updated' => $liveUpdated,
            'excursion_detected'  => $excursion,
            'duplicate'           => false,
        ];
    }

    /**
     * T-13 — a buffered hour arrives as one request, not sixty.
     *
     * Two rules make this safe to point hardware at:
     *
     * **Oldest first.** The readings are sorted by their own clock before any
     * are written, because `vehicle_live_status` only moves forward. A buffer
     * delivered newest-first would otherwise leave the live row showing the
     * oldest ping in the batch.
     *
     * **One bad ping does not lose the hour.** Each reading is accepted or
     * rejected on its own and the response says which. Failing the whole batch
     * on a single dead-probe row would throw away fifty-nine good positions,
     * and the device has no way to resend just the good ones.
     */
    public function ingestBatch(array $readings, ?int $companyId = null): array
    {
        // The device's own clock decides the order, not the order it happened
        // to serialise them in.
        usort($readings, function (array $a, array $b) {
            return strcmp((string) ($a['recorded_at'] ?? ''), (string) ($b['recorded_at'] ?? ''));
        });

        $accepted = [];
        $rejected = [];
        $duplicates = 0;
        $excursions = 0;

        foreach ($readings as $index => $reading) {
            try {
                $result = $this->ingest($reading, $companyId);

                if (! empty($result['duplicate'])) {
                    $duplicates++;
                } else {
                    $accepted[] = $result['telemetry_record_id'];
                }

                if (! empty($result['excursion_detected'])) {
                    $excursions++;
                }
            } catch (\Throwable $e) {
                // The index is reported so a device can identify which of its
                // buffered readings the server would not take.
                $rejected[] = [
                    'index'  => $index,
                    'device' => $reading['device_id'] ?? null,
                    'at'     => $reading['recorded_at'] ?? null,
                    'why'    => $e->getMessage(),
                ];
            }
        }

        return [
            'received'   => count($readings),
            'accepted'   => count($accepted),
            'duplicates' => $duplicates,
            'rejected'   => $rejected,
            'excursions' => $excursions,
            'record_ids' => $accepted,
        ];
    }

    /**
     * Is this exception a unique-constraint violation?
     *
     * SQLSTATE 23000 covers integrity violations across MySQL and SQLite, which
     * is what the tests run on. Matched on the SQLSTATE rather than the driver
     * message so it does not depend on wording that varies between versions.
     */
    private function isDuplicateKey(QueryException $e): bool
    {
        return (string) $e->getCode() === '23000'
            || str_contains(strtolower($e->getMessage()), 'unique constraint')
            || str_contains(strtolower($e->getMessage()), 'duplicate entry');
    }

    /**
     * A device knows its own id and nothing else — it cannot tell us which
     * company it belongs to, so the vehicle row is what resolves tenancy.
     */
    private function resolveVehicle(string $deviceId, ?int $companyId = null): Vehicle
    {
        // T-07 — a per-device token says which company is calling, so the
        // lookup is scoped and the ambiguity below cannot arise. This is the
        // whole practical reason those tokens exist.
        $matches = Vehicle::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('gps_device_id', $deviceId)
            ->get();

        if ($matches->isEmpty()) {
            throw new BusinessException('No vehicle is registered to this device.', 404);
        }

        // Only reachable on the legacy fleet-wide secret. gps_device_id is
        // unique per COMPANY, so two workspaces can both claim one device id,
        // and a shared secret says nothing about which is calling. Guessing
        // would write a position and a temperature onto another company's
        // truck, so it refuses — and says what fixes it.
        if ($matches->count() > 1) {
            throw new BusinessException(
                'This device id is registered in more than one company and cannot be resolved '
                .'from a shared secret. Issue this unit its own device token.',
                409
            );
        }

        return $matches->first();
    }

    /** Tier 1: one row per vehicle, and never dragged backwards in time. */
    private function refreshLiveStatus(Vehicle $vehicle, Carbon $recordedAt, array $reading): bool
    {
        $live = VehicleLiveStatus::find($vehicle->id);

        if (
            $live
            && config('stos.ingest.reject_stale_live_updates', true)
            && $live->last_ping_at
            && $recordedAt->lt($live->last_ping_at)
        ) {
            return false;
        }

        VehicleLiveStatus::updateOrCreate(
            ['vehicle_id' => $vehicle->id],
            [...$reading, 'company_id' => $vehicle->company_id, 'last_ping_at' => $recordedAt]
        );

        return true;
    }

    /**
     * Genset off while the body is warmer than the limit — the load is warming
     * with nothing cooling it.
     *
     * Raised on the READING, so a buffered replay still reports the excursion
     * that happened; the event carries `wasLive` so a listener can tell a
     * live breach from history.
     */
    private function checkExcursion(Vehicle $vehicle, array $reading, Carbon $recordedAt, bool $wasLive, int $recordId): bool
    {
        $temperature = $reading['temperature'];
        $generator = $reading['generator_status'];
        $offState = (string) config('stos.telemetry.excursion_generator_off', 'off');
        $threshold = (float) config('stos.telemetry.excursion_temperature', -18.0);

        // A silent probe is not a cold load. Never infer an excursion from a
        // missing reading — and never from a missing generator state either.
        if ($temperature === null || $generator === null) {
            return false;
        }

        if ($generator !== $offState || (float) $temperature <= $threshold) {
            return false;
        }

        // M2 adds motion to the rule: genset OFF, warm, AND moving.
        //
        // This deliberately silences a reefer parked with its genset off, which
        // is the common false alarm. It also silences a LOADED trailer standing
        // in a yard with a dead genset, which is a real spoilage the fleet would
        // now hear nothing about. That gap closes when Dispatch can tell us a
        // vehicle is loaded; the switch is config, not code, so it can be turned
        // off the day that becomes the wrong trade. See config/stos.php.
        if (config('stos.telemetry.excursion_requires_motion', true)) {
            $speed = $reading['speed'];
            if ($speed === null || (float) $speed <= 0) {
                return false;
            }
        }

        TelemetryExcursionDetected::dispatch(
            $vehicle,
            (float) $temperature,
            $threshold,
            $generator,
            $recordedAt->toDateTimeString(),
            $wasLive,
            $recordId,
        );

        return true;
    }
}
