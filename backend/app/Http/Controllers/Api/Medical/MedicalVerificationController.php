<?php

namespace App\Http\Controllers\Api\Medical;

use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseWorkerMedical;
use App\Models\Tpv\TpvWorkerMedical;
use App\Support\Medical\MedicalQcStatus;

/**
 * Public certificate verification — what the QR on a prescription opens.
 *
 * Unauthenticated by design: the whole point is that a guard, a client or an
 * inspector can check a certificate they were handed. So the answer is
 * deliberately thin — is this a real certificate, is it still valid, who signed
 * it — and carries no medical findings, no contact details and no worker
 * identifiers beyond the name on the document they are already holding.
 *
 * An unknown number returns the same shape with valid=false rather than a 404,
 * so scanning a forged code cannot be used to probe which numbers exist.
 */
class MedicalVerificationController extends Controller
{
    public function show(string $certificate)
    {
        $record = $this->find($certificate);

        if (! $record) {
            return response()->json([
                'data' => [
                    'certificate_no' => $certificate,
                    'found'          => false,
                    'valid'          => false,
                    'message'        => 'No certificate with this number was found.',
                ],
            ]);
        }

        $approved = MedicalQcStatus::isCleared($record->qc_status) || $record->qc_status === null;
        $expired  = $record->isExpired();
        $valid    = $approved && $record->isPassing() && ! $expired;

        return response()->json([
            'data' => [
                'certificate_no' => $record->certificate_no,
                'found'          => true,
                'valid'          => $valid,
                'message'        => match (true) {
                    $valid    => 'This certificate is valid.',
                    $expired  => 'This certificate has expired.',
                    ! $approved => 'This certificate has not been approved by the quality team.',
                    default   => 'This certificate does not clear the worker for site.',
                },
                'worker_name'   => $this->workerName($record),
                'fitness'       => $record->fitness_label,
                'exam_date'     => optional($record->exam_date)->toDateString(),
                'valid_until'   => optional($record->valid_until ?? $record->expiry_date)->toDateString(),
                'doctor_name'   => $record->examiner_name,
                'license_no'    => $record->doctor_license_no,
                'issued_at'     => optional($record->created_at)->toDateTimeString(),
            ],
        ]);
    }

    /**
     * The number itself says which register to look in (MED-TPV / MED-PUR), but
     * both are checked anyway — a mistyped middle segment should not make a real
     * certificate unverifiable.
     */
    private function find(string $certificate)
    {
        return TpvWorkerMedical::where('certificate_no', $certificate)->first()
            ?? PurchaseWorkerMedical::where('certificate_no', $certificate)->first();
    }

    private function workerName($record): ?string
    {
        $worker = $record->worker;

        return $worker?->name ?? $worker?->full_name;
    }
}
