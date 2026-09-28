<?php

namespace App\Domains\Fleet\Services;

use App\Domains\Fleet\Models\TyreFitment;
use App\Domains\Fleet\Models\TyreMaster;
use App\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * STOS-MAINT — the casing register, rotation, retreading and the wear forecast
 * (T-36 / T-37 / T-38).
 *
 * `TyreService` records what is on which axle. This owns the ASSET: what it
 * cost, where it has been, how many lives it has had, and when it will need
 * replacing. The split matters because a fitment ends and a casing does not.
 */
class TyreMasterService
{
    /** How far ahead the wear forecast is willing to guess. */
    private const FORECAST_HORIZON_KM = 200_000;

    /* ── The register ───────────────────────────────────────────────── */

    public function register(int $companyId, array $data, ?int $userId = null): TyreMaster
    {
        $serial = $this->serial($data['serial_number'] ?? '');

        if ($serial === '') {
            throw new BusinessException('A tyre needs the serial stamped on its casing.', 422);
        }

        $clash = TyreMaster::withTrashed()->where('company_id', $companyId)
            ->where('serial_number', $serial)->first();

        if ($clash) {
            throw new BusinessException(
                'That casing is already on the register'.($clash->trashed() ? ', withdrawn.' : '.'),
                422
            );
        }

        $master = TyreMaster::create([
            ...$this->fields($data),
            'company_id'    => $companyId,
            'serial_number' => $serial,
            'status'        => TyreMaster::IN_STOCK,
            'created_by'    => $userId,
            'updated_by'    => $userId,
        ]);

        Log::channel('stos')->info('Tyre registered', [
            'company_id' => $companyId, 'tyre_master_id' => $master->id,
            'serial_number' => $serial, 'user_id' => $userId,
        ]);

        return $master->fresh();
    }

    public function update(int $masterId, int $companyId, array $data, ?int $userId = null): TyreMaster
    {
        $master = $this->find($masterId, $companyId);

        if (array_key_exists('status', $data) && $data['status'] !== null
            && ! in_array($data['status'], TyreMaster::MANUALLY_SETTABLE, true)) {
            throw new BusinessException(match ($data['status']) {
                TyreMaster::FITTED    => 'Fitting it to a vehicle is what sets that.',
                TyreMaster::RETREADED => 'Sending it to the retreader is what sets that.',
                default               => 'That status is not set from here.',
            }, 422);
        }

        $master->fill([...$this->fields($data), 'updated_by' => $userId]);

        if (($data['status'] ?? null) !== null) {
            $master->status = $data['status'];
        }

        $master->save();

        return $master->fresh();
    }

    /**
     * The store list, and what is on the road.
     *
     * The question "what is in stock right now" had no answer before T-36 —
     * `tyre_fitments` only ever held tyres that had been fitted to something,
     * so a casing in the rack was invisible until somebody put it on a truck.
     */
    public function register_list(int $companyId, array $filters = []): array
    {
        $rows = TyreMaster::forCompany($companyId)
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['brand'] ?? null, fn ($q, $b) => $q->where('brand', $b))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where('serial_number', 'like', '%'.$this->serial($term).'%'))
            ->with('activeFitment.vehicle:id,registration_number', 'activeFitment.trailer:id,trailer_number')
            ->orderBy('serial_number')
            ->get();

        return [
            'tyres'  => $rows->map(fn (TyreMaster $t) => $this->present($t))->all(),
            'counts' => [
                'total'     => $rows->count(),
                'in_stock'  => $rows->where('status', TyreMaster::IN_STOCK)->count(),
                'fitted'    => $rows->where('status', TyreMaster::FITTED)->count(),
                'retreaded' => $rows->where('status', TyreMaster::RETREADED)->count(),
                'scrapped'  => $rows->where('status', TyreMaster::SCRAPPED)->count(),
            ],
        ];
    }

    /* ── T-37: rotation and retreading ──────────────────────────────── */

    /**
     * Swap two fitted tyres between their positions, in one operation.
     *
     * Not "remove both, fit both": doing it as four separate acts writes four
     * rows and two of them are lies — the casings never went into the store and
     * never came back out, and a cost-per-km built from that history would
     * count the swap as two new fitments.
     *
     * So the fitments are closed and reopened inside ONE transaction, at the
     * SAME odometer, with a note saying it was a rotation.
     */
    public function rotate(int $companyId, int $firstFitmentId, int $secondFitmentId, ?float $odometer = null, ?int $userId = null): array
    {
        if ($firstFitmentId === $secondFitmentId) {
            throw new BusinessException('A tyre cannot be rotated with itself.', 422);
        }

        $a = $this->fitment($firstFitmentId, $companyId);
        $b = $this->fitment($secondFitmentId, $companyId);

        foreach ([$a, $b] as $f) {
            if (! in_array($f->status, TyreFitment::ON_VEHICLE, true)) {
                throw new BusinessException('Only tyres that are currently fitted can be rotated.', 422);
            }
        }

        // Rotation moves a tyre around ONE vehicle or ONE trailer. Swapping
        // between two assets is two fitments and should be recorded as such —
        // otherwise the register says a casing moved without ever coming off.
        if ((int) $a->vehicle_id !== (int) $b->vehicle_id || (int) $a->trailer_id !== (int) $b->trailer_id) {
            throw new BusinessException(
                'Those two tyres are on different assets. Rotation is a swap of positions on one; '
                .'moving a tyre elsewhere is a removal and a fitting.',
                422
            );
        }

        if ($a->position === $b->position) {
            throw new BusinessException('Those two tyres are already in the same position.', 422);
        }

        $at = $odometer ?? $a->odometer_at_fitment;

        return DB::transaction(function () use ($a, $b, $at, $companyId, $userId) {
            $swapped = [];

            foreach ([[$a, $b->position], [$b, $a->position]] as [$fitment, $newPosition]) {
                $fitment->update([
                    'status'              => TyreFitment::REMOVED,
                    'odometer_at_removal' => $at,
                    'removed_on'          => now()->toDateString(),
                    'note'                => trim(($fitment->note ? $fitment->note.' · ' : '').'Rotated to '.$newPosition),
                ]);

                $swapped[] = TyreFitment::create([
                    'company_id'          => $companyId,
                    'tyre_id'             => $fitment->tyre_id,
                    'tyre_master_id'      => $fitment->tyre_master_id,
                    'vehicle_id'          => $fitment->vehicle_id,
                    'trailer_id'          => $fitment->trailer_id,
                    'position'            => $newPosition,
                    'status'              => TyreFitment::FITTED,
                    // Carried across, not reset: the tyre did not grow tread by
                    // moving axle, and a blank here would hide a worn casing.
                    'tread_depth'         => $fitment->tread_depth,
                    'odometer_at_fitment' => $at,
                    'fitted_on'           => now()->toDateString(),
                    'note'                => 'Rotated from '.$fitment->position,
                ]);
            }

            Log::channel('stos')->info('Tyres rotated', [
                'company_id' => $companyId, 'user_id' => $userId,
                'fitments' => [$a->id, $b->id], 'odometer' => $at,
            ]);

            return $swapped;
        });
    }

    /**
     * Send a casing to the retreader and bring it back as stock.
     *
     * The count is what decides whether another life is possible, and the cost
     * is what makes cost-per-km honest — a casing on its third life has been
     * paid for three times.
     */
    public function retread(int $masterId, int $companyId, ?float $cost = null, ?float $newDepth = null, ?int $userId = null): TyreMaster
    {
        $master = $this->find($masterId, $companyId);

        if ($master->status === TyreMaster::SCRAPPED) {
            throw new BusinessException('That casing is scrapped. It cannot be retreaded.', 422);
        }

        if ($master->status === TyreMaster::FITTED) {
            throw new BusinessException(
                'That tyre is still on a vehicle. Take it off before sending it to the retreader.',
                422
            );
        }

        $master->update([
            'status'             => TyreMaster::RETREADED,
            'retread_count'      => $master->retread_count + 1,
            'retread_cost_total' => (float) $master->retread_cost_total + (float) ($cost ?? 0),
            'new_tread_depth'    => $newDepth ?? $master->new_tread_depth,
            'updated_by'         => $userId,
        ]);

        Log::channel('stos')->info('Tyre retreaded', [
            'company_id' => $companyId, 'tyre_master_id' => $master->id,
            'retread_count' => $master->retread_count, 'cost' => $cost, 'user_id' => $userId,
        ]);

        return $master->fresh();
    }

    public function scrap(int $masterId, int $companyId, ?string $reason = null, ?int $userId = null): TyreMaster
    {
        $master = $this->find($masterId, $companyId);

        if ($master->status === TyreMaster::FITTED) {
            throw new BusinessException('That tyre is still on a vehicle. Take it off before scrapping it.', 422);
        }

        if (! $reason) {
            // A scrapped casing is money written off, and "why" is the only
            // thing that makes the next purchase decision better.
            throw new BusinessException('Say why the casing is being scrapped.', 422);
        }

        $master->update([
            'status'       => TyreMaster::SCRAPPED,
            'scrapped_on'  => now()->toDateString(),
            'scrap_reason' => $reason,
            'updated_by'   => $userId,
        ]);

        return $master->fresh();
    }

    /* ── T-36 / T-38: what it cost, and when it dies ────────────────── */

    /**
     * Distance, cost per kilometre, and the wear forecast for one casing.
     *
     * ── DISTANCE IS DERIVED, NEVER STORED ─────────────────────────────────
     * Summed from the fitments each time rather than kept as a running total
     * on the master. A cached figure and the rows it came from disagree the
     * first time somebody corrects an odometer, and the rows are the evidence.
     */
    public function economics(int $masterId, int $companyId): array
    {
        $master = $this->find($masterId, $companyId);

        $fitments = TyreFitment::forCompany($companyId)
            ->where('tyre_master_id', $master->id)
            ->orderBy('id')->get();

        $km = 0.0;
        $measured = [];

        foreach ($fitments as $f) {
            $from = $f->odometer_at_fitment === null ? null : (float) $f->odometer_at_fitment;
            $to   = $f->odometer_at_removal === null ? null : (float) $f->odometer_at_removal;

            if ($from !== null && $to !== null && $to > $from) {
                $km += $to - $from;
            }

            if ($f->tread_depth !== null && $from !== null) {
                $measured[] = ['odometer' => $from, 'depth' => (float) $f->tread_depth];
            }
        }

        $km = round($km, 1);
        $cost = $master->lifetime_cost;

        return [
            'tyre_master_id' => $master->id,
            'serial_number'  => $master->serial_number,
            'lives'          => $master->retread_count + 1,
            'km_run'         => $km,
            'lifetime_cost'  => $cost,
            // Null rather than zero when there is nothing to divide: a casing
            // with no recorded distance has no cost per km, and printing 0.00
            // would read as "free".
            'cost_per_km'    => ($cost !== null && $km > 0) ? round($cost / $km, 4) : null,
            'wear'           => $this->wear($master, $measured, $km),
        ];
    }

    /**
     * T-38 — millimetres lost per 10,000 km, and when that reaches the floor.
     *
     * Two measurements and a distance between them is the whole method. It is
     * deliberately not cleverer than that: a workshop can check the arithmetic
     * on a clipboard, and a projection nobody can check is a projection nobody
     * acts on.
     *
     * Everything is reported as null rather than guessed when the evidence is
     * thin, and `basis` says which case it is so the screen can explain itself
     * instead of showing a blank.
     */
    private function wear(TyreMaster $master, array $measured, float $kmRun): array
    {
        $blank = ['mm_per_10000km' => null, 'current_depth' => null,
                  'km_remaining' => null, 'replace_by_km' => null, 'basis' => 'no_measurements'];

        if (count($measured) < 2) {
            return [...$blank, 'basis' => count($measured) === 1 ? 'one_measurement' : 'no_measurements',
                    'current_depth' => $measured[0]['depth'] ?? null];
        }

        $first = $measured[0];
        $last  = $measured[count($measured) - 1];

        $span = $last['odometer'] - $first['odometer'];
        $lost = $first['depth'] - $last['depth'];

        // A casing that gained tread has been retreaded between the two
        // readings, so the pair does not describe one wear curve. Reported as
        // its own case rather than as a negative rate nobody can act on.
        if ($span <= 0 || $lost <= 0) {
            return [...$blank, 'current_depth' => $last['depth'], 'basis' => 'no_wear_measured'];
        }

        $rate = round(($lost / $span) * 10_000, 2);
        $floor = $master->scrap_tread_depth === null ? null : (float) $master->scrap_tread_depth;

        if ($floor === null || $last['depth'] <= $floor || $rate <= 0) {
            return [
                'mm_per_10000km' => $rate, 'current_depth' => $last['depth'],
                'km_remaining' => $floor !== null && $last['depth'] <= $floor ? 0 : null,
                'replace_by_km' => null,
                'basis' => $floor === null ? 'no_scrap_depth_set' : 'at_or_below_floor',
            ];
        }

        $remaining = round((($last['depth'] - $floor) / $rate) * 10_000);

        return [
            'mm_per_10000km' => $rate,
            'current_depth'  => $last['depth'],
            // Capped: projecting 800,000 km from two readings a fortnight apart
            // is arithmetic, not a forecast, and a screen showing it would be
            // believed once and never again.
            'km_remaining'   => (int) min($remaining, self::FORECAST_HORIZON_KM),
            'replace_by_km'  => $remaining > self::FORECAST_HORIZON_KM ? null : (int) round($last['odometer'] + $remaining),
            'basis'          => $remaining > self::FORECAST_HORIZON_KM ? 'beyond_horizon' : 'measured',
        ];
    }

    /* ── Helpers ────────────────────────────────────────────────────── */

    private function present(TyreMaster $t): array
    {
        $fitment = $t->activeFitment;

        return [
            'id'             => $t->id,
            'serial_number'  => $t->serial_number,
            'brand'          => $t->brand,
            'size'           => $t->size,
            'pattern'        => $t->pattern,
            'status'         => $t->status,
            'purchase_cost'  => $t->purchase_cost === null ? null : (float) $t->purchase_cost,
            'lifetime_cost'  => $t->lifetime_cost,
            'retread_count'  => $t->retread_count,
            'new_tread_depth'   => $t->new_tread_depth === null ? null : (float) $t->new_tread_depth,
            'scrap_tread_depth' => $t->scrap_tread_depth === null ? null : (float) $t->scrap_tread_depth,
            'fitted_to'      => $fitment?->vehicle?->registration_number
                ?? $fitment?->trailer?->trailer_number,
            'position'       => $fitment?->position,
            'scrapped_on'    => $t->scrapped_on?->toDateString(),
            'scrap_reason'   => $t->scrap_reason,
        ];
    }

    private function fields(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'brand', 'size', 'pattern', 'purchase_cost', 'purchase_date', 'supplier',
            'new_tread_depth', 'scrap_tread_depth', 'note',
        ]));
    }

    private function serial(?string $value): string
    {
        return strtoupper(trim((string) $value));
    }

    public function find(int $id, int $companyId): TyreMaster
    {
        $master = TyreMaster::forCompany($companyId)->find($id);

        if (! $master) {
            throw new BusinessException('That tyre is not on your register.', 404);
        }

        return $master;
    }

    private function fitment(int $id, int $companyId): TyreFitment
    {
        $fitment = TyreFitment::forCompany($companyId)->find($id);

        if (! $fitment) {
            throw new BusinessException('That fitment is not on your register.', 404);
        }

        return $fitment;
    }
}
