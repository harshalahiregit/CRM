<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\TrainingCertificateService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * L&D → Training Certificates (Phase 6). Generate / expire / upload / download.
 * Writes require HR-queue management. Tenant-scoped, audited.
 */
class TrainingCertificateController extends Controller
{
    public function __construct(private TrainingCertificateService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->service->list($this->tenant($request), $request->only(['status', 'employee_id'])));
    }

    public function show(Request $request, int $id)
    {
        return response()->json($this->service->show($id, $this->tenant($request)));
    }

    public function store(Request $request)
    {
        $this->can($request);
        $data = $request->validate([
            'employee_training_id' => 'required|integer',
            'issue_date'           => 'required|date',
            'expiry_date'          => 'nullable|date',
        ]);

        return response()->json($this->service->generate($data, $this->tenant($request), $request->user()), 201);
    }

    public function upload(Request $request, int $id)
    {
        $this->can($request);
        $request->validate(['certificate' => 'required|file|max:10240']);
        $file = $request->file('certificate');
        $path = $file->storeAs(
            "hr/documents/training/certificates/tenant_{$this->tenant($request)}",
            Str::random(8).'_'.time().'.'.strtolower($file->getClientOriginalExtension()),
            TrainingCertificateService::DOC_DISK
        );

        return response()->json($this->service->uploadFile($id, $path, $this->tenant($request), $request->user()));
    }

    public function expire(Request $request, int $id)
    {
        $this->can($request);

        return response()->json($this->service->expire($id, $this->tenant($request), $request->user()));
    }

    public function download(Request $request, int $id)
    {
        $cert = $this->service->recordDownload($id, $this->tenant($request), $request->user());

        // Serve the uploaded file if present; otherwise render a generated certificate PDF.
        if ($cert->certificate_file && Storage::disk(TrainingCertificateService::DOC_DISK)->exists($cert->certificate_file)) {
            return Storage::disk(TrainingCertificateService::DOC_DISK)->download($cert->certificate_file, $cert->certificate_number.'.'.pathinfo($cert->certificate_file, PATHINFO_EXTENSION));
        }

        $cert->loadMissing('assignment.employee', 'assignment.program', 'assignment.session');

        return Pdf::loadView('pdf.training_certificate', ['c' => $cert])->setPaper('a4', 'landscape')->download($cert->certificate_number.'.pdf');
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage certificates');
    }
}
