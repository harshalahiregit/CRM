<?php

namespace App\Services\Tpv;

use App\Exceptions\BusinessException;
use App\Models\Tpv\TpvGateAttendance;
use App\Models\Tpv\TpvWorker;
use App\Models\Tpv\TpvWorkerPpeIssue;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Repositories\Tpv\TpvWorkerRepository;
use App\Support\Tpv\TpvMedicalFitness as Fitness;
use App\Support\Tpv\TpvPpeItem as Ppe;
use App\Support\Tpv\TpvWorkerStatus as Status;
use App\Support\Vendor\VendorStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Workforce registration — the 5-step flow (profile → medical → HSSE induction →
 * PPE → entry badge) and the gates that guard site access.
 *
 * The gates are centralised in blockers(): both the wizard's step status and the
 * badge-issue action read from the same rules, so the UI can never advertise a
 * badge the API would refuse.
 */
class TpvWorkerService
{
    public function __construct(private TpvWorkerRepository $workerRepository)
    {
    }

    public function list(int $tenantId, array $filters): Collection
    {
        return $this->workerRepository->filtered($tenantId, $filters);
    }

    /* ── Step 1 — profile ───────────────────────────────────────────────── */

    public function create(array $data, User $actor): TpvWorker
    {
        $tenantId = $actor->tenant_id;
        $this->assertVendor($data['vendor_id'], $tenantId);
        $this->assertAadharUnique($data['aadhar_number'] ?? null, $tenantId);

        $worker = TpvWorker::create([
            ...$data,
            'tenant_id'    => $tenantId,
            'created_by'   => $actor->id,
            'current_step' => 1,
            'status'       => Status::DRAFT,
        ]);

        $worker->recordAudit('Worker Registered', $actor, null, ['worker_code' => $worker->worker_code]);

        Log::channel('tpv')->info('TPV worker registered', [
            'worker_id' => $worker->id, 'vendor_id' => $worker->vendor_id, 'tenant_id' => $tenantId,
        ]);

        return $worker->fresh(['vendor']);
    }

    public function update(TpvWorker $worker, array $data, User $actor): TpvWorker
    {
        if (! $worker->isEditable()) {
            throw new BusinessException('Only a Draft worker can be edited.');
        }
        if (! empty($data['vendor_id'])) {
            $this->assertVendor($data['vendor_id'], $worker->tenant_id);
        }
        $this->assertAadharUnique($data['aadhar_number'] ?? null, $worker->tenant_id, $worker->id);

        $worker->update($data);
        $worker->recordAudit('Worker Updated', $actor);

        Log::channel('tpv')->info('TPV worker updated', [
            'worker_id' => $worker->id, 'tenant_id' => $worker->tenant_id,
        ]);

        return $worker->fresh(['vendor']);
    }

    /* ── Step 2 — medical ───────────────────────────────────────────────── */

    public function saveMedical(TpvWorker $worker, array $data, User $actor): TpvWorker
    {
        if (! $worker->isEditable()) {
            throw new BusinessException('This worker is no longer editable.');
        }

        // Band the screening score server-side — the scoring rule is ours, not
        // the client's.
        $data['screening_band'] = Fitness::bandForScore($data['screening_score'] ?? null);

        $worker->medical()->updateOrCreate(
            ['tpv_worker_id' => $worker->id],
            [...$data, 'tenant_id' => $worker->tenant_id, 'recorded_by' => $actor->id]
        );
        $worker->update(['current_step' => max($worker->current_step, 2)]);

        $worker->recordAudit('Medical Recorded', $actor, null, ['fitness' => $data['fitness_status'] ?? null]);

        Log::channel('tpv')->info('TPV worker medical recorded', [
            'worker_id' => $worker->id, 'tenant_id' => $worker->tenant_id,
        ]);

        return $worker->fresh(['medical']);
    }

    /* ── Step 3 — HSSE induction ────────────────────────────────────────── */

    public function saveInduction(TpvWorker $worker, array $data, User $actor): TpvWorker
    {
        if (! $worker->isEditable()) {
            throw new BusinessException('This worker is no longer editable.');
        }

        $worker->induction()->updateOrCreate(
            ['tpv_worker_id' => $worker->id],
            [...$data, 'tenant_id' => $worker->tenant_id, 'recorded_by' => $actor->id]
        );
        $worker->update(['current_step' => max($worker->current_step, 3)]);

        $worker->recordAudit('Induction Recorded', $actor, null, ['passed' => (bool) ($data['passed'] ?? false)]);

        Log::channel('tpv')->info('TPV worker induction recorded', [
            'worker_id' => $worker->id, 'tenant_id' => $worker->tenant_id,
        ]);

        return $worker->fresh(['induction']);
    }

    /* ── Step 4 — PPE issuance ──────────────────────────────────────────── */

    public function issuePpe(TpvWorker $worker, array $data, User $actor): TpvWorker
    {
        if (! $worker->isEditable()) {
            throw new BusinessException('This worker is no longer editable.');
        }
        if (! Ppe::isValid($data['item'])) {
            throw new BusinessException("Unknown PPE item: {$data['item']}");
        }

        // Re-issuing the same item replaces the record (a worker holds one of each).
        $worker->ppeIssues()->updateOrCreate(
            ['tpv_worker_id' => $worker->id, 'item' => $data['item']],
            [
                ...$data,
                'tenant_id'   => $worker->tenant_id,
                'issued_by'   => $actor->id,
                'issued_date' => $data['issued_date'] ?? now()->toDateString(),
            ]
        );
        $worker->update(['current_step' => max($worker->current_step, 4)]);

        $worker->recordAudit('PPE Issued', $actor, null, ['item' => $data['item']]);

        Log::channel('tpv')->info('TPV worker PPE issued', [
            'worker_id' => $worker->id, 'tenant_id' => $worker->tenant_id, 'item' => $data['item'],
        ]);

        return $worker->fresh(['ppeIssues']);
    }

    public function removePpe(TpvWorker $worker, TpvWorkerPpeIssue $issue, User $actor): TpvWorker
    {
        if ((int) $issue->tpv_worker_id !== (int) $worker->id) {
            throw new BusinessException('PPE issue does not belong to this worker.', 404);
        }
        if (! $worker->isEditable()) {
            throw new BusinessException('This worker is no longer editable.');
        }

        $item = $issue->item;
        $issue->delete();
        $worker->recordAudit('PPE Removed', $actor, null, ['item' => $item]);

        return $worker->fresh(['ppeIssues']);
    }

    /* ── Step 5 — badge issue (the gate) ────────────────────────────────── */

    /**
     * Issue the entry badge and activate the worker for site access. Every gate
     * must pass; the QR token is a fresh bearer credential for the gate scan.
     */
    public function activate(TpvWorker $worker, User $actor, ?string $validUntil = null): TpvWorker
    {
        if ($worker->status === Status::ACTIVE) {
            throw new BusinessException('This worker already holds an active badge.');
        }
        if ($worker->status !== Status::DRAFT) {
            throw new BusinessException("A {$worker->status_label} worker cannot be badged.");
        }

        $blockers = $this->blockers($worker);
        if ($blockers !== []) {
            throw new BusinessException('Cannot issue badge — '.implode(' ', $blockers));
        }

        $year  = date('Y');
        $count = TpvWorker::withTrashed()->where('tenant_id', $worker->tenant_id)
            ->whereNotNull('badge_number')->whereYear('badge_issued_at', $year)->count() + 1;

        $worker->update([
            'status'            => Status::ACTIVE,
            'badge_number'      => 'BDG-'.$year.'-'.str_pad((string) $count, 3, '0', STR_PAD_LEFT),
            'qr_token'          => Str::random(48),
            'badge_issued_at'   => now(),
            'badge_issued_by'   => $actor->id,
            'badge_valid_until' => $validUntil ?: now()->addYear()->toDateString(),
            'current_step'      => 5,
        ]);

        $worker->recordAudit('Badge Issued', $actor, null, [
            'badge_number' => $worker->badge_number, 'valid_until' => $worker->badge_valid_until?->toDateString(),
        ]);

        Log::channel('tpv')->info('TPV worker badge issued', [
            'worker_id' => $worker->id, 'tenant_id' => $worker->tenant_id, 'actor_id' => $actor->id,
        ]);

        return $worker->fresh();
    }

    /**
     * Reveal the badge credential for (re)printing. The QR token is hidden on the
     * model by design, so every disclosure goes through here and is audited —
     * a reprint is a security-relevant act, not a plain read.
     */
    public function badge(TpvWorker $worker, User $actor): array
    {
        if ($worker->status !== Status::ACTIVE || ! $worker->qr_token) {
            throw new BusinessException('This worker does not hold an active badge.');
        }

        $worker->recordAudit('Badge Viewed', $actor, null, ['badge_number' => $worker->badge_number]);

        Log::channel('tpv')->info('TPV worker badge revealed', [
            'worker_id' => $worker->id, 'tenant_id' => $worker->tenant_id, 'actor_id' => $actor->id,
        ]);

        return [
            'badge_number'      => $worker->badge_number,
            'qr_token'          => $worker->qr_token,
            'badge_issued_at'   => $worker->badge_issued_at?->toIso8601String(),
            'badge_valid_until' => $worker->badge_valid_until?->toDateString(),
        ];
    }

    /** Suspend site access — the badge stops admitting but is not destroyed. */
    public function suspend(TpvWorker $worker, User $actor, ?string $remarks = null): TpvWorker
    {
        if ($worker->status !== Status::ACTIVE) {
            throw new BusinessException('Only an Active worker can be suspended.');
        }

        $worker->update(['status' => Status::SUSPENDED, 'remarks' => $remarks ?? $worker->remarks]);
        $worker->recordAudit('Worker Suspended', $actor, $remarks, ['to' => Status::SUSPENDED]);

        Log::channel('tpv')->info('TPV worker suspended', [
            'worker_id' => $worker->id, 'tenant_id' => $worker->tenant_id,
        ]);

        return $worker->fresh();
    }

    public function reinstate(TpvWorker $worker, User $actor): TpvWorker
    {
        if ($worker->status !== Status::SUSPENDED) {
            throw new BusinessException('Only a Suspended worker can be reinstated.');
        }
        // Re-check the gates — a medical may have lapsed while suspended.
        $blockers = $this->blockers($worker);
        if ($blockers !== []) {
            throw new BusinessException('Cannot reinstate — '.implode(' ', $blockers));
        }

        $worker->update(['status' => Status::ACTIVE]);
        $worker->recordAudit('Worker Reinstated', $actor, null, ['to' => Status::ACTIVE]);

        return $worker->fresh();
    }

    /**
     * Terminate site access permanently. The QR token is cleared so a retained
     * physical card can never resolve at the gate again.
     */
    public function terminate(TpvWorker $worker, User $actor, ?string $remarks = null): TpvWorker
    {
        if ($worker->status === Status::TERMINATED) {
            throw new BusinessException('This worker is already terminated.');
        }

        $worker->update(['status' => Status::TERMINATED, 'qr_token' => null, 'remarks' => $remarks ?? $worker->remarks]);

        // Terminating destroys the QR token, so a worker caught mid-shift could
        // never scan out — close any open attendance rather than strand the row.
        TpvGateAttendance::where('tpv_worker_id', $worker->id)
            ->whereNotNull('check_in_at')->whereNull('check_out_at')
            ->get()->each(function ($att) {
                $att->update([
                    'check_out_at'    => now(),
                    'check_out_gate'  => 'System — access terminated',
                    'minutes_on_site' => $att->check_in_at->diffInMinutes(now()),
                ]);
            });

        $worker->recordAudit('Worker Terminated', $actor, $remarks, ['to' => Status::TERMINATED]);

        Log::channel('tpv')->info('TPV worker terminated', [
            'worker_id' => $worker->id, 'tenant_id' => $worker->tenant_id,
        ]);

        return $worker->fresh();
    }

    public function destroy(TpvWorker $worker): void
    {
        if ($worker->status !== Status::DRAFT) {
            throw new BusinessException('Only a Draft worker can be deleted.');
        }

        $worker->delete();

        Log::channel('tpv')->info('TPV worker deleted', [
            'worker_id' => $worker->id, 'tenant_id' => $worker->tenant_id,
        ]);
    }

    /* ── Gates ──────────────────────────────────────────────────────────── */

    /**
     * Every reason this worker may not hold a site badge, in human terms. One
     * list, read by both stepStatus() and activate().
     */
    public function blockers(TpvWorker $worker): array
    {
        $b = [];

        // 1. The employing vendor must have cleared TPV onboarding.
        $vendor = $worker->vendor;
        if (! $vendor) {
            $b[] = 'The employing vendor no longer exists.';
        } elseif ($vendor->status !== VendorStatus::ACTIVE) {
            $b[] = "Vendor {$vendor->company_name} is {$vendor->status_label} — complete their onboarding first.";
        }

        // 2. Profile completeness + the statutory age floor.
        foreach ([
            'name'          => 'Name',
            'dob'           => 'Date of birth',
            'designation'   => 'Designation',
            'mobile'        => 'Mobile number',
            'aadhar_number' => 'Aadhar number',
        ] as $field => $label) {
            if (empty($worker->{$field})) {
                $b[] = "{$label} is missing.";
            }
        }
        if ($worker->dob && $worker->age !== null && $worker->age < 18) {
            $b[] = "Worker is {$worker->age} — must be at least 18.";
        }

        // 3. Medical — Unfit is a hard stop.
        $medical = $worker->medical;
        if (! $medical) {
            $b[] = 'Medical examination not recorded.';
        } elseif (! $medical->isPassing()) {
            $b[] = 'Medical outcome is Unfit.';
        }

        // 4. HSSE induction.
        $induction = $worker->induction;
        if (! $induction) {
            $b[] = 'HSSE induction not recorded.';
        } elseif (! $induction->passed) {
            $b[] = 'HSSE induction not passed.';
        }

        // 5. Mandatory PPE.
        $missing = array_diff(Ppe::MANDATORY, $this->issuedPpeItems($worker));
        if ($missing !== []) {
            $b[] = 'Mandatory PPE not issued: '.implode(', ', array_map(fn ($i) => Ppe::label($i), $missing)).'.';
        }

        return $b;
    }

    /** Per-step completion for the 5-step wizard UI. */
    public function stepStatus(TpvWorker $worker): array
    {
        $medical   = $worker->medical;
        $induction = $worker->induction;
        $issued    = $this->issuedPpeItems($worker);
        $mandatory = array_intersect(Ppe::MANDATORY, $issued);

        $profileDone = ! empty($worker->name) && $worker->dob && ! empty($worker->designation)
            && ! empty($worker->mobile) && ! empty($worker->aadhar_number)
            && $worker->age !== null && $worker->age >= 18;

        return [
            'current_step' => $worker->current_step,
            'blockers'     => $this->blockers($worker),
            'can_activate' => $worker->status === Status::DRAFT && $this->blockers($worker) === [],
            'steps' => [
                ['step' => 1, 'key' => 'profile',   'label' => 'Profile',   'complete' => $profileDone,
                 'detail' => $profileDone ? 'Complete' : 'Incomplete'],
                ['step' => 2, 'key' => 'medical',   'label' => 'Medical',   'complete' => (bool) $medical?->isPassing(),
                 'detail' => $medical ? Fitness::label($medical->fitness_status) : 'Not recorded'],
                ['step' => 3, 'key' => 'induction', 'label' => 'Induction', 'complete' => (bool) $induction?->passed,
                 'detail' => $induction ? ($induction->passed ? 'Passed' : 'Not passed') : 'Not recorded'],
                ['step' => 4, 'key' => 'ppe',       'label' => 'PPE',       'complete' => count($mandatory) === count(Ppe::MANDATORY),
                 'detail' => count($mandatory).'/'.count(Ppe::MANDATORY).' mandatory · '.count($issued).' issued'],
                ['step' => 5, 'key' => 'badge',     'label' => 'Entry Badge', 'complete' => $worker->status === Status::ACTIVE,
                 'detail' => $worker->badge_number ?: Status::label($worker->status)],
            ],
        ];
    }

    public function stats(int $tenantId): array
    {
        $base = fn () => TpvWorker::forTenant($tenantId);

        return [
            'total'      => $base()->count(),
            'draft'      => $base()->where('status', Status::DRAFT)->count(),
            'active'     => $base()->where('status', Status::ACTIVE)->count(),
            'suspended'  => $base()->where('status', Status::SUSPENDED)->count(),
            'terminated' => $base()->where('status', Status::TERMINATED)->count(),
            // Badges lapsing within 30 days — the renewal queue.
            'expiring'   => $base()->where('status', Status::ACTIVE)
                                   ->whereNotNull('badge_valid_until')
                                   ->whereBetween('badge_valid_until', [now()->toDateString(), now()->addDays(30)->toDateString()])
                                   ->count(),
        ];
    }

    /* ── Internals ──────────────────────────────────────────────────────── */

    private function issuedPpeItems(TpvWorker $worker): array
    {
        return $worker->ppeIssues()->pluck('item')->unique()->values()->all();
    }

    private function assertVendor(int $vendorId, int $tenantId): Vendor
    {
        $vendor = Vendor::forTenant($tenantId)->find($vendorId);
        if (! $vendor) {
            throw new BusinessException('Vendor not found.', 404);
        }

        return $vendor;
    }

    /** One Aadhar per tenant — catches the same worker registered twice. */
    private function assertAadharUnique(?string $aadhar, int $tenantId, ?int $exceptId = null): void
    {
        if (! $aadhar) {
            return;
        }

        $dup = TpvWorker::forTenant($tenantId)->where('aadhar_number', $aadhar)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->first();

        if ($dup) {
            throw new BusinessException("A worker with this Aadhar is already registered — {$dup->worker_code} ({$dup->name}).");
        }
    }
}
