<?php

namespace App\Domains\Fleet\Services;

use App\Domains\Fleet\Models\MaintenanceJob;
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
                'complaint'       => $data['complaint'] ?? null,
                'diagnosis'       => $data['diagnosis'] ?? null,
                'parts_cost'      => $data['parts_cost'] ?? 0,
                'labour_cost'     => $data['labour_cost'] ?? 0,
                'total_cost'      => $this->total($data),
                'status'          => $data['status'] ?? 'open',
                'is_safety_critical' => (bool) ($data['is_safety_critical'] ?? false),
                'opened_at'       => now(),
            ]);

            // The vehicle comes off the road with the card. Doing this here and
            // not in the controller is what keeps the two in step.
            $vehicle->update(['status' => 'in_maintenance']);

            Log::channel('stos')->info('Job card opened', [
                'company_id' => $companyId, 'user_id' => $userId,
                'vehicle_id' => $vehicle->id, 'job_card' => $number,
                'safety_critical' => $job->is_safety_critical,
            ]);

            return $job;
        });
    }

    /** Parts, labour, diagnosis and progress, without closing the card. */
    public function update(int $jobId, int $companyId, array $data, int $userId): MaintenanceJob
    {
        $job = $this->job($jobId, $companyId);

        if (in_array($job->status, ['completed', 'cancelled'], true)) {
            throw new BusinessException('That job card is already closed. Open a new one for further work.');
        }

        $job->fill([
            'complaint'   => $data['complaint']   ?? $job->complaint,
            'diagnosis'   => $data['diagnosis']   ?? $job->diagnosis,
            'parts_cost'  => $data['parts_cost']  ?? $job->parts_cost,
            'labour_cost' => $data['labour_cost'] ?? $job->labour_cost,
            'status'      => $data['status']      ?? $job->status,
            'is_safety_critical' => $data['is_safety_critical'] ?? $job->is_safety_critical,
        ]);

        $job->total_cost = $this->total([
            'parts_cost'  => $job->parts_cost,
            'labour_cost' => $job->labour_cost,
            'total_cost'  => $data['total_cost'] ?? null,
        ]);

        $job->save();

        Log::channel('stos')->info('Job card updated', [
            'company_id' => $companyId, 'user_id' => $userId,
            'job_card' => $job->job_card_number, 'changed' => array_keys($data),
        ]);

        return $job->fresh();
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

        if ($job->status === 'completed') {
            throw new BusinessException('That job card is already closed.');
        }

        return DB::transaction(function () use ($job, $companyId, $data, $userId) {
            $job->fill([
                'diagnosis'   => $data['diagnosis']   ?? $job->diagnosis,
                'parts_cost'  => $data['parts_cost']  ?? $job->parts_cost,
                'labour_cost' => $data['labour_cost'] ?? $job->labour_cost,
                'status'      => 'completed',
                'closed_at'   => now(),
                'qc_passed'   => (bool) ($data['qc_passed'] ?? true),
                'released_by' => $userId,
            ]);

            $job->total_cost = $this->total([
                'parts_cost'  => $job->parts_cost,
                'labour_cost' => $job->labour_cost,
                'total_cost'  => $data['total_cost'] ?? null,
            ]);

            $job->save();

            $release = $this->tryRelease($job->vehicle_id, $companyId, (bool) $job->qc_passed);

            Log::channel('stos')->info('Job card closed', [
                'company_id' => $companyId, 'user_id' => $userId,
                'job_card' => $job->job_card_number, 'total_cost' => $job->total_cost,
                'vehicle_released' => $release['released'],
            ]);

            return ['job' => $job->fresh(), 'release' => $release];
        });
    }

    /**
     * Put the vehicle back on the road, or say exactly what still holds it.
     *
     * Never a bare false: the workshop needs to know whether to chase the
     * compliance desk or another bay.
     */
    private function tryRelease(int $vehicleId, int $companyId, bool $qcPassed): array
    {
        $vehicle = $this->vehicle($vehicleId, $companyId);
        $holds = [];

        if (! $qcPassed) {
            $holds[] = ['code' => 'qc_failed', 'why' => 'QC did not pass on this job card.', 'owner' => 'Workshop supervisor'];
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

        $vehicle->update(['status' => 'active']);

        return ['released' => true, 'status' => 'active', 'holds' => []];
    }

    /** The workshop board: open cards first, newest first within each group. */
    public function board(int $companyId, ?string $status = null): array
    {
        $jobs = MaintenanceJob::forCompany($companyId)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->with('vehicle:id,registration_number,vehicle_type,status')
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

    /* ── helpers ────────────────────────────────────────────────── */

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
