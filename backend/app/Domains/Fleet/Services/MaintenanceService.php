<?php

namespace App\Domains\Fleet\Services;

use App\Domains\Fleet\Integration\TripCostPublisher;
use App\Domains\Fleet\Models\MaintenanceJob;
use App\Domains\Fleet\Models\MaintenanceJobLabour;
use App\Domains\Fleet\Models\MaintenanceJobPart;
use App\Domains\Fleet\Models\Vehicle;
use App\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * STOS-MAINT — a job card from complaint to release.
 *
 * Opening a card takes the vehicle off the road; closing it puts the vehicle
 * back only if nothing else is holding it. Both sides of that are here, in one
 * transaction each, because a vehicle status that disagrees with its job cards
 * is how a truck gets dispatched with its brakes in pieces.
 */
class MaintenanceService
{
    public function open(int $companyId, array $data, int $userId): MaintenanceJob
    {
        $vehicle = $this->vehicle((int) $data['vehicle_id'], $companyId);

        $number = trim((string) ($data['job_card_number'] ?? '')) ?: $this->nextJobCardNumber($companyId);

        if (MaintenanceJob::forCompany($companyId)->where('job_card_number', $number)->exists()) {
            throw new BusinessException("Job card {$number} already exists.");
        }

        return DB::transaction(function () use ($vehicle, $companyId, $data, $number, $userId) {
            $job = MaintenanceJob::create([
                'company_id'      => $companyId,
                'job_card_number' => $number,
                'vehicle_id'      => $vehicle->id,
                // A breakdown on the road belongs to the trip it happened on;
                // routine servicing leaves this null and stays fleet overhead.
                'trip_id'         => $data['trip_id'] ?? null,
                'workshop_name'   => $data['workshop_name'] ?? null,
                'complaint'       => $data['complaint'] ?? null,
                'diagnosis'       => $data['diagnosis'] ?? null,
                'status'          => $data['status'] ?? MaintenanceJob::OPEN,
                'is_safety_critical' => (bool) ($data['is_safety_critical'] ?? false),
                'opened_at'       => now(),
            ]);

            $this->applyCosts($job, $companyId, $data);

            // The vehicle comes off the road with the card. Doing this here and
            // not in the controller is what keeps the two in step.
            //
            // T-04 — a card that names a TRIP is a breakdown on the road, not a
            // booked workshop slot. A planner reading "in the workshop" assumes
            // a return time; a breakdown means a load is stranded somewhere and
            // somebody is arranging recovery. Same card, different fact.
            $vehicle->update([
                'status' => $job->trip_id ? Vehicle::STATUS_BREAKDOWN : Vehicle::STATUS_UNDER_MAINTENANCE,
            ]);

            Log::channel('stos')->info('Job card opened', [
                'company_id' => $companyId, 'user_id' => $userId,
                'vehicle_id' => $vehicle->id, 'job_card' => $number,
                'safety_critical' => $job->is_safety_critical,
            ]);

            return $job->fresh();
        });
    }

    /** Parts, labour, diagnosis and progress, without closing the card. */
    public function update(int $jobId, int $companyId, array $data, int $userId): MaintenanceJob
    {
        $job = $this->job($jobId, $companyId);

        if (in_array($job->status, MaintenanceJob::CLOSED_STATES, true)) {
            throw new BusinessException('That job card is already closed. Open a new one for further work.');
        }

        return DB::transaction(function () use ($job, $companyId, $data, $userId) {
            $job->fill([
                'complaint'     => $data['complaint']     ?? $job->complaint,
                'diagnosis'     => $data['diagnosis']     ?? $job->diagnosis,
                'workshop_name' => $data['workshop_name'] ?? $job->workshop_name,
                'status'        => $data['status']        ?? $job->status,
                'is_safety_critical' => $data['is_safety_critical'] ?? $job->is_safety_critical,
                'road_tested'   => $data['road_tested']   ?? $job->road_tested,
            ]);

            $job->save();

            $this->applyCosts($job, $companyId, $data);

            Log::channel('stos')->info('Job card updated', [
                'company_id' => $companyId, 'user_id' => $userId,
                'job_card' => $job->job_card_number, 'changed' => array_keys($data),
            ]);

            return $job->fresh();
        });
    }

    /**
     * Close the card and try to release the vehicle.
     *
     * "Try" is the point. The vehicle goes back to active ONLY when nothing
     * else holds it: no other open card, and papers that are actually valid.
     * Releasing unconditionally is how a truck leaves the workshop fixed and
     * still uninsured.
     */
    public function close(int $jobId, int $companyId, array $data, int $userId): array
    {
        $job = $this->job($jobId, $companyId);

        if ($job->status === MaintenanceJob::COMPLETED) {
            throw new BusinessException('That job card is already closed.');
        }

        return DB::transaction(function () use ($job, $companyId, $data, $userId) {
            $verdict = $this->verdict($data);
            $closedAt = now();

            $job->fill([
                'diagnosis'     => $data['diagnosis']     ?? $job->diagnosis,
                'workshop_name' => $data['workshop_name'] ?? $job->workshop_name,
                'status'        => MaintenanceJob::COMPLETED,
                'closed_at'     => $closedAt,
                // Both are written from one verdict so they cannot drift.
                'qc_result'     => $verdict,
                'qc_passed'     => $verdict === MaintenanceJob::QC_PASS,
                // Only a pass can clear anything; naming a card while failing
                // QC yourself is meaningless, so it is dropped rather than stored.
                'clears_job_id' => $verdict === MaintenanceJob::QC_PASS
                    ? ($data['clears_job_id'] ?? null)
                    : null,
                'road_tested'   => (bool) ($data['road_tested'] ?? $job->road_tested),
                // T-33 — fixed at closure. Computing it on read would make a
                // historic card's downtime grow every time somebody looked.
                'downtime_hours' => $this->downtimeHours($job->opened_at, $closedAt),
                'released_by'   => $userId,
            ]);

            $job->save();

            $this->applyCosts($job, $companyId, $data);

            $job = $job->fresh();

            $release = $this->tryRelease($job->vehicle_id, $companyId, $job);

            // C-06 — published on closure, not on opening: the cost is not
            // known until then, and the dedupe key would refuse to correct a
            // zero posted early.
            app(TripCostPublisher::class)->publishMaintenance($companyId, $job);

            Log::channel('stos')->info('Job card closed', [
                'company_id' => $companyId, 'user_id' => $userId,
                'job_card' => $job->job_card_number, 'total_cost' => $job->total_cost,
                'qc_result' => $job->qc_result, 'downtime_hours' => $job->downtime_hours,
                'vehicle_released' => $release['released'],
            ]);

            return ['job' => $job, 'release' => $release];
        });
    }

    /**
     * Put the vehicle back on the road, or say exactly what still holds it.
     *
     * Never a bare false: the workshop needs to know whether to chase the
     * compliance desk or another bay.
     */
    private function tryRelease(int $vehicleId, int $companyId, MaintenanceJob $job): array
    {
        $vehicle = $this->vehicle($vehicleId, $companyId);
        $holds = [];

        if ($job->qc_result === MaintenanceJob::QC_CRITICAL_FAIL) {
            $holds[] = [
                'code'  => 'qc_critical_fail',
                'why'   => 'QC recorded a critical failure on this job card.',
                'owner' => 'Workshop supervisor',
            ];
        } elseif (! $job->qc_passed) {
            $holds[] = ['code' => 'qc_failed', 'why' => 'QC did not pass on this job card.', 'owner' => 'Workshop supervisor'];
        }

        // T-31 — a critical failure outlives its own card.
        $condemning = $this->standingCondemnation($vehicleId, $companyId, $job);

        if ($condemning) {
            $holds[] = [
                'code'  => 'qc_critical_fail_standing',
                'why'   => 'Job card '.$condemning->job_card_number.' condemned this vehicle and nothing has cleared it.',
                'owner' => 'Workshop supervisor',
            ];
        }

        $otherOpen = MaintenanceJob::forCompany($companyId)
            ->where('vehicle_id', $vehicleId)
            ->whereIn('status', MaintenanceJob::OPEN_STATES)
            ->count();

        if ($otherOpen > 0) {
            $holds[] = [
                'code'  => 'other_jobs_open',
                'why'   => $otherOpen.' other job '.($otherOpen === 1 ? 'card is' : 'cards are').' still open on this vehicle.',
                'owner' => 'Workshop supervisor',
            ];
        }

        if (in_array($vehicle->compliance_status, ['expired', 'blocked'], true)) {
            $holds[] = [
                'code'  => 'compliance_blocked',
                'why'   => 'The vehicle is mechanically ready but its papers are not valid.',
                'owner' => 'Fleet compliance desk',
            ];
        }

        if ($holds !== []) {
            return ['released' => false, 'status' => $vehicle->status, 'holds' => $holds];
        }

        $vehicle->update(['status' => Vehicle::STATUS_AVAILABLE]);

        return ['released' => true, 'status' => Vehicle::STATUS_AVAILABLE, 'holds' => []];
    }

    /**
     * The condemnation still standing against this vehicle, if any.
     *
     * A critical failure is cleared only by a later card that passes QC AND
     * names it in `clears_job_id`. Deliberately not "any later pass": a routine
     * oil change closed with a pass would otherwise un-condemn a vehicle that
     * failed on its brakes, which is the exact accident this hold exists to
     * prevent. Clearing is an act somebody performs and signs, not a side
     * effect of unrelated work.
     */
    private function standingCondemnation(int $vehicleId, int $companyId, MaintenanceJob $current): ?MaintenanceJob
    {
        $condemnations = MaintenanceJob::forCompany($companyId)
            ->where('vehicle_id', $vehicleId)
            ->where('qc_result', MaintenanceJob::QC_CRITICAL_FAIL)
            ->orderByDesc('id')->get();

        foreach ($condemnations as $condemnation) {
            // The card being closed right now counts as a clearance too, so a
            // re-test does not need a second round trip to release the vehicle.
            if ($current->qc_result === MaintenanceJob::QC_PASS
                && (int) $current->clears_job_id === (int) $condemnation->id) {
                continue;
            }

            $cleared = MaintenanceJob::forCompany($companyId)
                ->where('clears_job_id', $condemnation->id)
                ->where('qc_result', MaintenanceJob::QC_PASS)
                ->exists();

            if (! $cleared) {
                return $condemnation;
            }
        }

        return null;
    }

    /**
     * What is condemning this vehicle right now, for the screens to show.
     *
     * Null is the common answer; when it is not null the vehicle cannot be
     * released by closing any other card, and the workshop needs to see which
     * card to answer.
     */
    public function condemnationFor(int $vehicleId, int $companyId): ?array
    {
        $condemnations = MaintenanceJob::forCompany($companyId)
            ->where('vehicle_id', $vehicleId)
            ->where('qc_result', MaintenanceJob::QC_CRITICAL_FAIL)
            ->orderByDesc('id')->get();

        foreach ($condemnations as $condemnation) {
            $cleared = MaintenanceJob::forCompany($companyId)
                ->where('clears_job_id', $condemnation->id)
                ->where('qc_result', MaintenanceJob::QC_PASS)
                ->exists();

            if (! $cleared) {
                return [
                    'id'              => $condemnation->id,
                    'job_card_number' => $condemnation->job_card_number,
                    'closed_at'       => optional($condemnation->closed_at)->toIso8601String(),
                ];
            }
        }

        return null;
    }

    /** The workshop board: open cards first, newest first within each group. */
    public function board(int $companyId, ?string $status = null): array
    {
        $jobs = MaintenanceJob::forCompany($companyId)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->with(['vehicle:id,registration_number,vehicle_type,status', 'parts', 'labour'])
            ->orderByDesc('id')->limit(200)->get();

        $byStatus = [];
        foreach (MaintenanceJob::STATUSES as $s) {
            $byStatus[$s] = 0;
        }

        foreach (MaintenanceJob::forCompany($companyId)->select('status')->get() as $row) {
            $byStatus[$row->status] = ($byStatus[$row->status] ?? 0) + 1;
        }

        return [
            'jobs'   => $jobs->all(),
            'counts' => $byStatus,
            'open_safety_critical' => MaintenanceJob::forCompany($companyId)
                ->where('is_safety_critical', true)
                ->whereIn('status', MaintenanceJob::OPEN_STATES)->count(),
        ];
    }

    /**
     * T-33 — how long this vehicle has been off the road, and on how many cards.
     *
     * Closed cards only: an open card's downtime is still running, and adding a
     * moving number to a historic total makes the total meaningless.
     */
    public function downtimeFor(int $vehicleId, int $companyId): array
    {
        $rows = MaintenanceJob::forCompany($companyId)
            ->where('vehicle_id', $vehicleId)
            ->whereNotNull('downtime_hours')
            ->get(['downtime_hours']);

        $hours = 0.0;
        foreach ($rows as $row) {
            $hours += (float) $row->downtime_hours;
        }

        return [
            'total_hours' => number_format($hours, 2, '.', ''),
            'total_days'  => number_format($hours / 24, 2, '.', ''),
            'cards'       => $rows->count(),
        ];
    }

    /* ── costs and line items ───────────────────────────────────── */

    /**
     * Write the line items, then set the totals from them.
     *
     * The itemisation is the truth when it is supplied: a card with parts rows
     * takes its `parts_cost` from those rows, so the stored total and the lines
     * can never disagree. A scalar `parts_cost` with no rows is still accepted,
     * because a card closed at the counter with a single settled figure is a
     * real thing, and refusing it would only push people to invent a fake line.
     */
    private function applyCosts(MaintenanceJob $job, int $companyId, array $data): void
    {
        $partsGiven  = array_key_exists('parts', $data) && is_array($data['parts']);
        $labourGiven = array_key_exists('labour', $data) && is_array($data['labour']);

        if ($partsGiven) {
            $job->parts_cost = $this->replaceParts($job, $companyId, $data['parts']);
        } elseif (array_key_exists('parts_cost', $data) && $data['parts_cost'] !== null) {
            $job->parts_cost = $data['parts_cost'];
        }

        if ($labourGiven) {
            $job->labour_cost = $this->replaceLabour($job, $companyId, $data['labour']);
        } elseif (array_key_exists('labour_cost', $data) && $data['labour_cost'] !== null) {
            $job->labour_cost = $data['labour_cost'];
        }

        $job->total_cost = $this->total([
            'parts_cost'  => $job->parts_cost,
            'labour_cost' => $job->labour_cost,
            'total_cost'  => $data['total_cost'] ?? null,
        ]);

        $job->save();
    }

    /** Replace this card's parts wholesale and return their summed cost. */
    private function replaceParts(MaintenanceJob $job, int $companyId, array $rows): string
    {
        MaintenanceJobPart::forCompany($companyId)->where('maintenance_job_id', $job->id)->delete();

        $sum = 0.0;

        foreach ($rows as $row) {
            $name = trim((string) ($row['part_name'] ?? ''));

            // A blank row is the form's empty starter line, not an entry.
            if ($name === '') {
                continue;
            }

            $line = MaintenanceJobPart::lineCost($row['quantity'] ?? 1, $row['unit_cost'] ?? 0);

            MaintenanceJobPart::create([
                'company_id'         => $companyId,
                'maintenance_job_id' => $job->id,
                'part_name'          => $name,
                'part_number'        => $row['part_number'] ?? null,
                'quantity'           => $row['quantity'] ?? 1,
                'unit_cost'          => $row['unit_cost'] ?? 0,
                'line_cost'          => $line,
                'supplier'           => $row['supplier'] ?? null,
                'warranty_months'    => $row['warranty_months'] ?? null,
            ]);

            $sum += (float) $line;
        }

        return number_format($sum, 2, '.', '');
    }

    /** Replace this card's labour wholesale and return its summed cost. */
    private function replaceLabour(MaintenanceJob $job, int $companyId, array $rows): string
    {
        MaintenanceJobLabour::forCompany($companyId)->where('maintenance_job_id', $job->id)->delete();

        $sum = 0.0;

        foreach ($rows as $row) {
            $type = trim((string) ($row['labour_type'] ?? ''));

            if ($type === '') {
                continue;
            }

            $line = MaintenanceJobLabour::lineCost($row['hours'] ?? 0, $row['hourly_rate'] ?? 0);

            MaintenanceJobLabour::create([
                'company_id'         => $companyId,
                'maintenance_job_id' => $job->id,
                'labour_type'        => $type,
                'hours'              => $row['hours'] ?? 0,
                'hourly_rate'        => $row['hourly_rate'] ?? 0,
                'line_cost'          => $line,
                'technician'         => $row['technician'] ?? null,
            ]);

            $sum += (float) $line;
        }

        return number_format($sum, 2, '.', '');
    }

    /* ── helpers ────────────────────────────────────────────────── */

    /**
     * One QC verdict from whichever field the caller sent.
     *
     * `qc_result` wins when present. A caller sending only the old boolean still
     * works and gets PASS or FAIL — never CRITICAL_FAIL, because the boolean
     * cannot express it and guessing the severe reading would strand vehicles.
     */
    private function verdict(array $data): string
    {
        $result = strtoupper(trim((string) ($data['qc_result'] ?? '')));

        if (in_array($result, MaintenanceJob::QC_RESULTS, true)) {
            return $result;
        }

        if (array_key_exists('qc_passed', $data) && $data['qc_passed'] !== null) {
            return filter_var($data['qc_passed'], FILTER_VALIDATE_BOOLEAN)
                ? MaintenanceJob::QC_PASS
                : MaintenanceJob::QC_FAIL;
        }

        // Nothing said: the historic default is that a card closes clean.
        return MaintenanceJob::QC_PASS;
    }

    /** Hours off the road, to two places; never negative. */
    private function downtimeHours($openedAt, $closedAt): string
    {
        if (! $openedAt) {
            return '0.00';
        }

        $hours = $openedAt->diffInMinutes($closedAt) / 60;

        return number_format(max($hours, 0), 2, '.', '');
    }

    /**
     * Parts + labour, unless a total was supplied.
     *
     * An explicit total is honoured because a signed job card may legitimately
     * differ — a discount, a warranty credit, a rounded settlement.
     */
    private function total(array $data): string
    {
        if (isset($data['total_cost']) && $data['total_cost'] !== null && $data['total_cost'] !== '') {
            return number_format((float) $data['total_cost'], 2, '.', '');
        }

        return number_format((float) ($data['parts_cost'] ?? 0) + (float) ($data['labour_cost'] ?? 0), 2, '.', '');
    }

    private function nextJobCardNumber(int $companyId): string
    {
        $year = now()->format('Y');
        $count = MaintenanceJob::forCompany($companyId)->where('job_card_number', 'like', "JC-{$year}-%")->count();

        return sprintf('JC-%s-%04d', $year, $count + 1);
    }

    private function vehicle(int $id, int $companyId): Vehicle
    {
        $vehicle = Vehicle::forCompany($companyId)->find($id);

        if (! $vehicle) {
            throw new BusinessException('That vehicle is not in your fleet.', 404);
        }

        return $vehicle;
    }

    private function job(int $id, int $companyId): MaintenanceJob
    {
        $job = MaintenanceJob::forCompany($companyId)->find($id);

        if (! $job) {
            throw new BusinessException('That job card does not exist.', 404);
        }

        return $job;
    }
}
