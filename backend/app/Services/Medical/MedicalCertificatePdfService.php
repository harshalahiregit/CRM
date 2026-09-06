<?php

namespace App\Services\Medical;

use App\Models\Tenant;
use App\Support\FrontendUrl;
use App\Support\Medical\HealthScore;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfInstance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The PDF prescription a doctor's examination produces.
 *
 * The document is the point of the internal flow: it has to be something a
 * guard, a client auditor or a labour inspector can hold and check. So it
 * carries, on its face, everything that makes it checkable — the doctor's
 * licence number and signature, the camera capture taken at sign-time, the
 * device's IP, where it was signed (as a place, not a pair of decimals), a
 * barcode of the certificate number and a QR to a public verification page.
 *
 * Written against the shared column set both worker-medical models expose, so
 * one renderer serves TPV and Purchase without either side importing the other.
 */
class MedicalCertificatePdfService
{
    public function __construct(private MedicalCertificateRenderer $marks) {}

    /**
     * Render (and cache) the certificate for a medical record.
     *
     * @param  Model  $medical  a TpvWorkerMedical or PurchaseWorkerMedical
     * @param  array{worker_name?:string, worker_code?:string, worker_photo?:string, vendor_name?:string, dob?:string, gender?:string, designation?:string}  $subject
     */
    public function render(Model $medical, array $subject = []): PdfInstance
    {
        return Pdf::loadView('pdf.medical_certificate', $this->context($medical, $subject))
            ->setPaper('a4', 'portrait');
    }

    /**
     * Render and store, returning the stored path. Cached on the record so the
     * same certificate is not re-rendered on every download, but regenerated
     * whenever the record has changed since.
     */
    /**
     * Render and store, but never at the cost of the examination itself.
     *
     * The certificate is generated the moment an examination is recorded,
     * because handing it over is the doctor's next action. But a PDF is a
     * rendering of a record that is already saved — if dompdf cannot produce it
     * (most commonly because the PHP GD extension is absent, which it needs to
     * embed the signature and the camera photo) the right outcome is a recorded
     * examination and no PDF yet, not a lost examination.
     *
     * Returns null when it could not render. The certificate regenerates on the
     * next download attempt, so enabling GD fixes every record retroactively.
     */
    public function tryStore(Model $medical, array $subject = []): ?string
    {
        try {
            return $this->store($medical, $subject);
        } catch (\Throwable $e) {
            Log::warning('Medical certificate could not be rendered; the examination is saved regardless', [
                'medical_id' => $medical->getKey(),
                'model'      => $medical::class,
                'error'      => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function store(Model $medical, array $subject = []): string
    {
        $path = 'medical/certificates/'.$medical->getKey().'-'.($medical->certificate_no ?: 'draft').'.pdf';

        Storage::disk('local')->put($path, $this->render($medical, $subject)->output());

        $medical->forceFill(['pdf_path' => $path])->save();

        return $path;
    }

    /** The public URL a scanner lands on — no login, verification only. */
    public function verifyUrl(?string $certificateNo): string
    {
        return FrontendUrl::to(rtrim((string) config('medical.certificate.verify_path', '/verify/medical/'), '/').'/'.$certificateNo);
    }

    /**
     * Everything the template prints, assembled here so the Blade file stays a
     * layout rather than a place where business decisions hide.
     */
    private function context(Model $medical, array $subject): array
    {
        $verifyUrl = $this->verifyUrl($medical->certificate_no);

        return [
            'medical'   => $medical,
            'subject'   => $subject,
            'company'   => $this->company((int) $medical->tenant_id),
            'verifyUrl' => $verifyUrl,
            'mapUrl'    => MedicalGeoResolver::mapUrl($medical->geo_location),
            'healthBand' => HealthScore::band($medical->health_score !== null ? (float) $medical->health_score : null),
            'scoreScale' => HealthScore::MAX,
            // Machine-readable marks, inlined as data URIs so dompdf never has
            // to fetch anything while rendering.
            'barcode'   => $medical->certificate_no ? $this->marks->barcodeDataUri($medical->certificate_no) : null,
            'qr'        => $this->marks->qrDataUri($verifyUrl),
            'signature' => $this->marks->imageDataUri($this->publicPath($medical->signature_path)),
            'capture'   => $this->marks->imageDataUri($this->publicPath($medical->capture_photo_path)),
        ];
    }

    /** Signature and capture live on the public disk; the PDF needs their real path. */
    private function publicPath(?string $relative): ?string
    {
        return $relative ? Storage::disk('public')->path($relative) : null;
    }

    /** Letterhead identity — tolerant of a slim tenant row. */
    private function company(int $tenantId): array
    {
        $t = Tenant::find($tenantId);

        return [
            'name'    => $t->name ?? 'Company',
            'address' => $t->address ?? null,
            'email'   => $t->email ?? null,
            'phone'   => $t->phone ?? null,
        ];
    }
}
