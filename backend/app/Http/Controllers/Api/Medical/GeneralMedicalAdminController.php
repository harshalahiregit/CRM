<?php

namespace App\Http\Controllers\Api\Medical;

use App\Http\Controllers\Controller;
use App\Models\Medical\GeneralMedical;
use App\Models\User;
use App\Services\Medical\GeneralMedicalReportService;
use App\Services\Medical\GeneralMedicalService;
use App\Services\Medical\MedicalCertificatePdfService;
use App\Support\Medical\MedicalQcStatus;
use App\Support\Tpv\TpvMedicalFitness;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * The admin's way IN to the general medical register.
 *
 * The doctor portal could examine internal staff, client contacts and site
 * visitors, and those examinations went into `general_medicals` — where nothing
 * read them. No register, no report, no page that showed one person's
 * certificate. A record nobody can open is not a record; it is a drawer with no
 * key, and it looked exactly like a working feature from the doctor's side.
 *
 * Three surfaces, deliberately: the LIST (every examination, filterable), the
 * ONE (a single examination in full, with that person's history beside it), and
 * the REPORT (the aggregates, including what was actually found and how many
 * people share it).
 *
 * Admin-only. These examinations are of employees and named visitors; the
 * vendor registers already have their own module-scoped reviewers, and this is
 * not theirs to read.
 */
class GeneralMedicalAdminController extends Controller
{
    public function __construct(
        private GeneralMedicalReportService $reports,
        private GeneralMedicalService $general,
        private MedicalCertificatePdfService $pdf,
    ) {}

    /** Every examination, newest first, filterable and paged. */
    public function index(Request $request)
    {
        $out = $this->reports->register($request->user()->tenant_id, $request->only([
            'audience', 'fitness', 'qc_status', 'doctor_id', 'from', 'to', 'q', 'page',
        ]));

        return response()->json($out + ['options' => $this->options($request)]);
    }

    /** One examination in full — the individual view. */
    public function show(Request $request, int $medical)
    {
        $data = $this->reports->one($request->user()->tenant_id, $medical);

        abort_unless($data, 404, 'Examination not found.');

        return response()->json(['data' => $data]);
    }

    /** The aggregates, including the findings people share. */
    public function report(Request $request)
    {
        return response()->json([
            'data'    => $this->reports->build($request->user()->tenant_id, $request->only([
                'audience', 'fitness', 'qc_status', 'doctor_id', 'from', 'to', 'q',
            ])),
            'options' => $this->options($request),
        ]);
    }

    /**
     * The certificate, streamed.
     *
     * Rendered on demand when the stored file is missing rather than 404ing:
     * a PDF that failed to generate at examination time (no GD extension on the
     * host, say) must not cost the admin sight of the record.
     */
    public function certificate(Request $request, int $medical)
    {
        $tenantId = $request->user()->tenant_id;
        $record   = GeneralMedical::forTenant($tenantId)->find($medical);

        abort_unless($record, 404, 'Examination not found.');

        $person = $this->general->describe($record->subject_type, (int) $record->subject_id, $tenantId) ?? [];
        $subject = [
            'name'    => $person['name'] ?? 'Unknown',
            'code'    => $person['email'] ?? null,
            'context' => $person['context'] ?? null,
            'label'   => GeneralMedical::SUBJECT_LABELS[$record->subject_type] ?? null,
        ];

        $path = $record->pdf_path && Storage::disk('local')->exists($record->pdf_path)
            ? $record->pdf_path
            : $this->pdf->store($record, $subject);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.($record->certificate_no ?: 'certificate').'.pdf"',
        ]);
    }

    /**
     * The vocabulary the filters need, so the client never hard-codes it.
     *
     * The doctor list is the doctors who have actually examined somebody in
     * this register — a filter offering people with nothing behind it is a
     * filter that returns nothing and looks broken.
     */
    private function options(Request $request): array
    {
        $tenantId = $request->user()->tenant_id;

        $doctorIds = GeneralMedical::forTenant($tenantId)
            ->whereNotNull('doctor_user_id')->distinct()->pluck('doctor_user_id');

        return [
            'audiences' => collect(GeneralMedicalReportService::AUDIENCE_LABELS)
                ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all(),
            'fitness_statuses' => TpvMedicalFitness::ALL,
            'qc_statuses'      => MedicalQcStatus::ALL,
            'doctors'          => User::where('tenant_id', $tenantId)->whereIn('id', $doctorIds)
                ->orderBy('name')->get(['id', 'name']),
        ];
    }
}
