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

    /* ── Step 4 — PPE issuance ──────────────────────────────────────────────
     |
     | Handled entirely by PpeInventoryService: it checks stock, decrements it,
     | writes the movement and derives the worker's PPE step state from the issues.
     |
     | The two methods that used to live here are gone on purpose. issuePpe() could
     | create an issue record with no inventory_item_id — PPE handed out with no
     | stock behind it — and removePpe() deleted an issue row without returning the
     | quantity, so deleting a record left Inventory permanently short.
     */

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

        // 5. Mandatory PPE — the required set is whatever Inventory tags 'mandatory'.
        $missing = app(PpeInventoryService::class)->missingMandatoryFor($worker);
        if ($missing->isNotEmpty()) {
            $b[] = 'Mandatory PPE not issued: '.$missing->pluck('name')->implode(', ').'.';
        }

        return $b;
    }

    /** Per-step completion for the 5-step wizard UI. */
    public function stepStatus(TpvWorker $worker): array
    {
        $medical   = $worker->medical;
        $induction = $worker->induction;
        $issued    = $this->issuedPpeItems($worker);
        // Requirements are role-based, so they are counted per worker, not per tenant.
        $compliance = app(PpeInventoryService::class)->complianceFor($worker);
        $required   = count($compliance['items']);
        $missing    = count($compliance['missing']);

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
                ['step' => 4, 'key' => 'ppe',       'label' => 'PPE',       'complete' => $missing === 0 && count($issued) > 0,
                 'detail' => $required === 0
                     ? 'No PPE required for this role · '.count($issued).' issued'
                     : ($required - $missing).'/'.$required.' required · '.count($issued).' issued'],
                ['step' => 5, 'key' => 'badge',     'label' => 'Entry Badge', 'complete' => $worker->status === Status::ACTIVE,
                 'detail' => $worker->badge_number ?: Status::label($worker->status)],
            ],
            // The ✓/✗ list, so a blocked badge can say exactly what is missing.
            'ppe_compliance' => $compliance,
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

    /**
     * The same counters, scoped to ONE vendor — what the portal needs.
     *
     * VendorPortalController::workerStats() has always called this, but it was never
     * defined, so GET /portal/workers/stats returned a 500. The vendor id is taken
     * from the token by the caller and applied here on top of the tenant, so a
     * portal user can only ever count their own workers.
     */
    public function statsForVendor(int $vendorId, int $tenantId): array
    {
        $base = fn () => TpvWorker::forTenant($tenantId)->where('vendor_id', $vendorId);

        return [
            'total'      => $base()->count(),
            'draft'      => $base()->where('status', Status::DRAFT)->count(),
            'active'     => $base()->where('status', Status::ACTIVE)->count(),
            'suspended'  => $base()->where('status', Status::SUSPENDED)->count(),
            'terminated' => $base()->where('status', Status::TERMINATED)->count(),
            // Anything not yet Active is still awaiting medical / induction / badging.
            'pending'    => $base()->whereNotIn('status', [Status::ACTIVE, Status::TERMINATED])->count(),
            'expiring'   => $base()->where('status', Status::ACTIVE)
                                   ->whereNotNull('badge_valid_until')
                                   ->whereBetween('badge_valid_until', [now()->toDateString(), now()->addDays(30)->toDateString()])
                                   ->count(),
        ];
    }

    /* ── Age Calculator ─────────────────────────────────────────────── */

    public static function calcAge(?string $dob): array
    {
        if (empty($dob)) return ['age' => null, 'age_reason' => ''];
        try {
            $age    = (new \DateTime())->diff(new \DateTime($dob))->y;
            $reason = '';
            if ($age < 18)     $reason = 'Underage';
            elseif ($age > 60) $reason = 'Overage';
            return ['age' => $age, 'age_reason' => $reason];
        } catch (\Exception $e) {
            return ['age' => null, 'age_reason' => ''];
        }
    }

    /* ── Bulk Upload Workers ────────────────────────────────────────── */

    public function bulkUpload(mixed $file, int $vendorId, int $tenantId, User $actor): array
    {
        $this->assertVendor($vendorId, $tenantId);

        $path = $file->getRealPath();
        $ext  = strtolower($file->getClientOriginalExtension());

        $tempExtractPath = null;
        $photosMap = []; // filename => full path
        $dataFile = null;
        $dataExt  = $ext;

        if ($ext === 'zip') {
            $zip = new \ZipArchive();
            if ($zip->open($path) === true) {
                $tempExtractPath = storage_path('app/temp_bulk_'.uniqid());
                $zip->extractTo($tempExtractPath);
                $zip->close();

                // Find data file inside extracted directory
                $allFiles = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tempExtractPath));
                foreach ($allFiles as $f) {
                    if ($f->isDir()) continue;
                    $fExt = strtolower($f->getExtension());
                    $fName = $f->getFilename();

                    if (in_array($fExt, ['csv', 'xls', 'xlsx']) && !$dataFile) {
                        $dataFile = $f->getRealPath();
                        $dataExt  = $fExt;
                    } elseif (in_array($fExt, ['jpg', 'jpeg', 'png', 'webp'])) {
                        $photosMap[strtolower($fName)] = $f->getRealPath();
                        $photosMap[pathinfo($fName, PATHINFO_FILENAME)] = $f->getRealPath();
                    }
                }

                if (!$dataFile) {
                    if ($tempExtractPath && file_exists($tempExtractPath)) {
                        \File::deleteDirectory($tempExtractPath);
                    }
                    throw new BusinessException('ZIP archive must contain a CSV or Excel file (workers.csv / workers.xlsx).');
                }
            } else {
                throw new BusinessException('Failed to open uploaded ZIP file.');
            }
        } else {
            $dataFile = $path;
        }

        $rows = [];
        if ($dataExt === 'csv' || $dataExt === 'txt') {
            if (($handle = fopen($dataFile, 'r')) !== false) {
                $isHeader = true;
                while (($row = fgetcsv($handle, 2000, ',')) !== false) {
                    // Remove UTF-8 BOM if present on first cell
                    if (isset($row[0])) {
                        $row[0] = preg_replace('/\x{EF}\x{BB}\x{BF}/u', '', $row[0]);
                    }
                    if ($isHeader) { $isHeader = false; continue; }
                    $rows[] = array_map('trim', $row);
                }
                fclose($handle);
            }
        } elseif (in_array($dataExt, ['xls', 'xlsx'])) {
            if (class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($dataFile);
                $sheet       = $spreadsheet->getActiveSheet();
                $rowIterator = $sheet->getRowIterator();
                $isHeader    = true;
                foreach ($rowIterator as $row) {
                    if ($isHeader) { $isHeader = false; continue; }
                    $cellIterator = $row->getCellIterator();
                    $cellIterator->setIterateOnlyExistingCells(false);
                    $rowData = [];
                    foreach ($cellIterator as $cell) {
                        $rowData[] = trim((string) $cell->getFormattedValue());
                    }
                    if (implode('', $rowData) === '') continue;
                    $rows[] = $rowData;
                }
            } else {
                if ($tempExtractPath && file_exists($tempExtractPath)) {
                    \File::deleteDirectory($tempExtractPath);
                }
                throw new BusinessException('Excel parsing requires PhpSpreadsheet library. Please use CSV format instead.');
            }
        } else {
            if ($tempExtractPath && file_exists($tempExtractPath)) {
                \File::deleteDirectory($tempExtractPath);
            }
            throw new BusinessException("Unsupported file format: {$ext}");
        }

        $inserted = 0;
        $skipped  = 0;
        $errors   = [];

        foreach ($rows as $index => $row) {
            $name = trim($row[0] ?? '');
            if (empty($name)) continue;

            $gender    = ucfirst(strtolower(trim($row[1] ?? 'Male')));
            $dobRaw    = trim($row[2] ?? '');
            $mobile    = preg_replace('/\D/', '', trim($row[3] ?? ''));
            $blood     = trim($row[4] ?? '');
            $desig     = trim($row[5] ?? 'Worker');
            $skill     = trim($row[6] ?? 'Unskilled');
            $aadhar    = trim($row[7] ?? '');
            $photoRef  = trim($row[8] ?? ''); // Column 9: optional Photo Filename

            if ($aadhar && !preg_match('/^\d{12}$/', $aadhar)) {
                $errors[] = 'Row '.($index + 2).': Invalid Aadhaar "'.$aadhar.'" — 12 digits required.';
                $skipped++;
                continue;
            }

            // Duplicate check
            $query = TpvWorker::where('tenant_id', $tenantId)->where('vendor_id', $vendorId);
            if (!empty($aadhar)) {
                $query->where('aadhar_number', $aadhar);
            } else {
                $query->where('name', $name);
                if ($mobile) $query->where('mobile', $mobile);
            }

            if ($query->exists()) {
                $skipped++;
                continue;
            }

            $dob = null;
            if ($dobRaw) {
                foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y'] as $fmt) {
                    $dt = \DateTime::createFromFormat($fmt, $dobRaw);
                    if ($dt) { $dob = $dt->format('Y-m-d'); break; }
                }
                if (!$dob) {
                    $ts = strtotime($dobRaw);
                    if ($ts) $dob = date('Y-m-d', $ts);
                }
            }

            $ageData = self::calcAge($dob);

            $count = TpvWorker::withTrashed()->where('tenant_id', $tenantId)->count() + 1;
            $workerCode = 'W-'.str_pad((string) $count, 5, '0', STR_PAD_LEFT);

            // Handle Profile Photo if matched in ZIP or provided
            $photoPath = null;
            $photoCandidateKeys = array_filter([
                strtolower($photoRef),
                pathinfo(strtolower($photoRef), PATHINFO_FILENAME),
                strtolower($aadhar),
                strtolower($mobile),
                strtolower(str_replace(' ', '_', $name)),
                strtolower($name)
            ]);

            foreach ($photoCandidateKeys as $key) {
                if (isset($photosMap[$key]) && file_exists($photosMap[$key])) {
                    $sourceImg = $photosMap[$key];
                    $imgExt = pathinfo($sourceImg, PATHINFO_EXTENSION);
                    $destFilename = 'workers/photos/bulk_'.uniqid().'.'.$imgExt;
                    \Storage::disk('public')->put($destFilename, file_get_contents($sourceImg));
                    $photoPath = $destFilename;
                    break;
                }
            }

            TpvWorker::create([
                'tenant_id'      => $tenantId,
                'vendor_id'      => $vendorId,
                'created_by'     => $actor->id,
                'worker_code'    => $workerCode,
                'name'           => $name,
                'gender'         => $gender,
                'dob'            => $dob,
                'age'            => $ageData['age'],
                'age_reason'     => $ageData['age_reason'],
                'mobile'         => $mobile,
                'blood_group'    => $blood,
                'designation'    => $desig,
                'skill_category' => $skill,
                'aadhar_number'  => $aadhar ?: null,
                'photo_path'     => $photoPath,
                'is_active'      => true,
                'current_step'   => 1,
                'status'         => Status::DRAFT,
            ]);

            $inserted++;
        }

        if ($tempExtractPath && file_exists($tempExtractPath)) {
            \File::deleteDirectory($tempExtractPath);
        }

        $msg = "{$inserted} worker(s) imported successfully.";
        if ($skipped > 0)    $msg .= " {$skipped} duplicate/skipped.";
        if (!empty($errors)) $msg .= " ".count($errors)." error(s).";

        return [
            'status'   => 'success',
            'message'  => $msg,
            'inserted' => $inserted,
            'skipped'  => $skipped,
            'errors'   => $errors,
        ];
    }

    /* ── Entry card ─────────────────────────────────────────────────── */

    /**
     * Record the gate entry-card decision for a worker.
     *
     * Lifted out of TpvWorkerController so the vendor portal can perform the
     * same action without a second copy of the rule — both callers land here.
     */
    public function markCard(TpvWorker $worker, int $cardStatus): TpvWorker
    {
        $worker->update([
            'card_status'    => $cardStatus,
            'card_issued_at' => now(),
        ]);

        return $worker;
    }

    /* ── 3-Punch Safety Strike Engine ───────────────────────────────── */

    public function applyPunch(TpvWorker $worker, int $punchCount, string $reason, User $actor, ?string $punchAt = null): TpvWorker
    {
        if (!in_array($punchCount, [1, 2, 3], true)) {
            throw new BusinessException('Invalid punch number. Must be 1, 2, or 3.');
        }

        $currentPunch = (int) ($worker->punch_count ?? 0);
        if ($punchCount <= $currentPunch) {
            throw new BusinessException("Punch {$punchCount} has already been applied to this worker.");
        }
        if ($punchCount !== $currentPunch + 1) {
            throw new BusinessException("Please apply Punch ".($currentPunch + 1)." first.");
        }

        $nowStr = $punchAt ?: now()->toDateTimeString();

        $log = $worker->punch_log ?: [];
        $log[] = [
            'num'        => $punchCount,
            'at'         => $nowStr,
            'reason'     => $reason,
            'applied_by' => $actor->name ?? 'Admin',
        ];

        $updateData = [
            'punch_count'                       => $punchCount,
            'punch_'.$punchCount.'_at'          => $nowStr,
            'punch_'.$punchCount.'_reason'      => $reason,
            'punch_log'                         => $log,
        ];

        // 3rd Punch triggers permanent site access termination
        if ($punchCount === 3) {
            $updateData['status']    = Status::TERMINATED;
            $updateData['is_active'] = false;
            $updateData['remarks']   = "PERMANENTLY BLOCKED — 3rd Punch Violation: {$reason}";

            TpvGateAttendance::where('tpv_worker_id', $worker->id)
                ->whereNull('check_out_at')
                ->update(['check_out_at' => now()]);
        }

        $worker->update($updateData);

        $labels = [1 => '1st Warning Punch', 2 => '2nd Final Warning Punch', 3 => '3rd Permanent Termination Punch'];
        $worker->recordAudit($labels[$punchCount], $actor, $reason, ['punch_count' => $punchCount]);

        Log::channel('tpv')->warning("TPV Worker Punch {$punchCount} Applied", [
            'worker_id' => $worker->id, 'reason' => $reason, 'actor_id' => $actor->id
        ]);

        return $worker->fresh();
    }

    /* ── Public QR Token Verification ───────────────────────────────── */

    public function getPublicScan(string $token): array
    {
        // Resolve by the QR token ONLY — the token is the bearer credential and a
        // terminated worker has it nulled, so a retained card stops resolving.
        // (Previously an `orWhere('worker_code')` let a terminated worker still
        // resolve by their printed code, defeating that.)
        $worker = TpvWorker::with('vendor:id,company_name,vendor_code,status')
            ->where('qr_token', $token)
            ->first();

        if (!$worker) {
            return [
                'found'   => false,
                'status'  => 'UNRECOGNISED',
                'message' => 'Invalid QR code. This pass is not registered in the system.',
            ];
        }

        $punch = (int) ($worker->punch_count ?? 0);

        // The public verification page must reflect the SAME access decision the
        // site gate makes — not just the punch count. It previously showed a green
        // "ACCESS ACTIVE" for a suspended worker, an expired badge, or an inactive
        // vendor, all of which the real gate (GateScanService::evaluate) denies.
        $terminated    = $punch >= 3 || $worker->status === Status::TERMINATED || ! $worker->is_active;
        $suspended     = $worker->status === Status::SUSPENDED;
        $notActive     = $worker->status !== Status::ACTIVE;   // e.g. still Draft — no badge issued
        $badgeExpired  = $worker->badge_valid_until && $worker->badge_valid_until->isPast();
        $vendorBlocked = $worker->vendor && $worker->vendor->status !== VendorStatus::ACTIVE;

        $isTerminated = $terminated;   // kept for backward-compatible payload key

        if ($terminated) {
            $stateLabel = 'PERMANENTLY BLOCKED'; $stateColor = 'red';
        } elseif ($suspended) {
            $stateLabel = 'ACCESS SUSPENDED'; $stateColor = 'red';
        } elseif ($badgeExpired) {
            $stateLabel = 'BADGE EXPIRED'; $stateColor = 'red';
        } elseif ($vendorBlocked) {
            $stateLabel = 'VENDOR NOT ACTIVE'; $stateColor = 'red';
        } elseif ($notActive) {
            $stateLabel = 'NO ACTIVE BADGE'; $stateColor = 'red';
        } elseif ($punch === 2) {
            $stateLabel = 'FINAL WARNING'; $stateColor = 'orange';
        } elseif ($punch === 1) {
            $stateLabel = 'FIRST WARNING'; $stateColor = 'yellow';
        } else {
            $stateLabel = 'ACCESS ACTIVE'; $stateColor = 'green';
        }
        // Anything red means the gate would refuse entry.
        $denied = $stateColor === 'red';

        return [
            'found'         => true,
            'worker'        => [
                'id'            => $worker->id,
                'worker_code'   => $worker->worker_code,
                'name'          => $worker->name,
                'gender'        => $worker->gender,
                'dob'           => $worker->dob?->toDateString(),
                'age'           => $worker->age,
                'blood_group'   => $worker->blood_group,
                'designation'   => $worker->designation,
                'badge_number'  => $worker->badge_number,
                'valid_until'   => $worker->badge_valid_until?->toDateString(),
                'photo_url'     => $worker->photo_path ? asset('storage/'.$worker->photo_path) : null,
                'company_name'  => $worker->vendor->company_name ?? 'TPV Vendor',
                'vendor_code'   => $worker->vendor->vendor_code ?? '—',
                'punch_count'   => $punch,
                'punch_1_at'    => $worker->punch_1_at?->toIso8601String(),
                'punch_1_reason'=> $worker->punch_1_reason,
                'punch_2_at'    => $worker->punch_2_at?->toIso8601String(),
                'punch_2_reason'=> $worker->punch_2_reason,
                'punch_3_at'    => $worker->punch_3_at?->toIso8601String(),
                'punch_3_reason'=> $worker->punch_3_reason,
                'punch_log'     => $worker->punch_log ?? [],
            ],
            'card_status'   => $worker->card_status == 1 ? 'Completed' : ($worker->card_status == 2 ? 'Skipped' : 'Pending'),
            'state_label'   => $stateLabel,
            'state_color'   => $stateColor,
            'is_terminated' => $isTerminated,
            'access_denied' => $denied,
            'scanned_at'    => now()->format('d M Y, H:i').' IST',
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
            $vendor = Vendor::forTenant($tenantId)->first();
        }
        if (! $vendor) {
            $vendor = Vendor::create([
                'tenant_id'    => $tenantId,
                'company_name' => 'TPV COMPANY',
                'status'       => 'Active',
            ]);
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
