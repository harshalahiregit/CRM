<?php

namespace App\Http\Controllers\Api\Medical;

use App\Http\Controllers\Controller;
use App\Http\Requests\Medical\SaveExaminationRequest;
use App\Models\Medical\GeneralMedical;
use App\Services\Medical\GeneralMedicalService;
use App\Services\Medical\MedicalCertificatePdfService;
use App\Support\Medical\MedicalFindings;
use App\Support\Medical\MedicalQcStatus;
use App\Support\Tpv\TpvMedicalFitness;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * The doctor portal, for everyone who is not a vendor worker.
 *
 * The portal could examine TPV and Purchase workers only, which left the
 * internal team, clients visiting site, and gate visitors with nowhere to be
 * examined at all. The three audiences here complete it.
 *
 * A separate controller from the vendor one on purpose. That one is built
 * around "pick a vendor, then a worker" — two levels, vendor-scoped, with each
 * side's own workforce service behind it. These three have no vendor above
 * them, so bolting them onto the same actions would mean a vendor_id that is
 * meaningless three times out of five and a branch in every method.
 *
 * The audience segment is `internal` | `client` | `visitor`; the register calls
 * the same three `user` | `client_contact` | `visitor`, and translating between
 * them in one place keeps the URL readable without leaking a column name.
 */
class DoctorGeneralController extends Controller
{
    /** URL segment → the subject_type stored on the record. */
    private const AUDIENCES = [
        'internal' => GeneralMedical::SUBJECT_USER,
        'client'   => GeneralMedical::SUBJECT_CLIENT,
        'visitor'  => GeneralMedical::SUBJECT_VISITOR,
    ];

    public function __construct(
        private GeneralMedicalService $general,
        private MedicalCertificatePdfService $pdf,
    ) {}

    /* ── Choosing who to examine ────────────────────────────────────────── */

    /**
     * The people of one audience, each carrying their medical standing.
     *
     * One flat, searchable list — no vendor step, because these audiences have
     * nothing above them to narrow by.
     */
    public function subjects(Request $request, string $audience)
    {
        $type = $this->typeFor($audience);
        $tenantId = $request->user()->tenant_id;

        $search = $request->query('q');
        $people = $this->general->subjects($type, $tenantId, $search);
        $total  = $this->general->countSubjects($type, $tenantId, $search);

        return response()->json([
            'data' => array_map(fn ($p) => $p + [
                'clearance' => $this->general->clearanceFor($type, $p['id'], $tenantId),
            ], $people),
            // The screen says how much it is not showing rather than drawing a
            // list that silently stops.
            'meta' => ['total' => $total, 'showing' => count($people), 'truncated' => $total > count($people)],
        ]);
    }

    /** One person: who they are, and every examination on record. */
    public function subject(Request $request, string $audience, int $subject)
    {
        $type = $this->typeFor($audience);
        $tenantId = $request->user()->tenant_id;

        $person = $this->general->describe($type, $subject, $tenantId);
        abort_unless($person, 404, 'That person is not in this workspace.');

        return response()->json([
            'data' => [
                'subject'   => $person + ['subject_type' => $type, 'audience' => $audience],
                'history'   => $this->general->history($type, $subject, $tenantId),
                'clearance' => $this->general->clearanceFor($type, $subject, $tenantId),
                'options'   => ['fitness_statuses' => TpvMedicalFitness::ALL],
            ],
        ]);
    }

    /**
     * What a set of people have IN COMMON.
     *
     * The same reading as the vendor sides get, for the audiences that have no
     * vendor above them. Ticking eleven visitors and reading eleven separate
     * certificates does not tell anybody that four of them share a finding;
     * this does, from records that already exist.
     */
    public function groupFindings(Request $request, string $audience)
    {
        $type     = $this->typeFor($audience);
        $tenantId = $request->user()->tenant_id;

        $ids = collect($request->input('ids', []))
            ->map(fn ($id) => (int) $id)->filter()->unique()->take(200)->values();

        if ($ids->isEmpty()) {
            return response()->json(['data' => MedicalFindings::group([])]);
        }

        $latest = $this->general->latestFor($type, $ids->all(), $tenantId);

        $people = $ids->map(function ($id) use ($type, $tenantId, $latest) {
            // Tenant-scoped: an id belonging to another workspace describes to
            // null and is dropped rather than reported on.
            $person = $this->general->describe($type, $id, $tenantId);

            return $person === null ? null : [
                'id'      => $id,
                'name'    => $person['name'] ?? 'Unknown',
                'context' => $person['context'] ?? null,
                'exam'    => isset($latest[$id]) ? $latest[$id]->attributesToArray() : null,
            ];
        })->filter()->values()->all();

        return response()->json(['data' => MedicalFindings::group($people)]);
    }

    /** Register a walk-in the system has never met, so they can be examined. */
    public function storeVisitor(Request $request)
    {
        $data = $request->validate([
            'name'            => 'required|string|max:160',
            'phone'           => 'nullable|string|max:40',
            'email'           => 'nullable|email|max:191',
            'company'         => 'nullable|string|max:160',
            'id_proof_type'   => 'nullable|string|max:40',
            'id_proof_number' => 'nullable|string|max:60',
            'purpose'         => 'nullable|string|max:255',
        ]);

        $visitor = $this->general->createVisitor($request->user()->tenant_id, $data, $request->user());

        return response()->json(['message' => 'Visitor registered.', 'data' => $visitor], 201);
    }

    /* ── The examination ────────────────────────────────────────────────── */

    public function examine(SaveExaminationRequest $request, string $audience, int $subject)
    {
        $type     = $this->typeFor($audience);
        $user     = $request->user();
        $tenantId = $user->tenant_id;

        // A certificate carries a licence number by law; refuse rather than
        // issue an unverifiable document.
        abort_unless((bool) $user->doctorProfile?->isSignable(), 422,
            'Add your licence number to your profile before recording an examination.');

        $person = $this->general->describe($type, $subject, $tenantId);
        abort_unless($person, 404, 'That person is not in this workspace.');

        $data = $request->validated();
        $data['report_file'] = $request->file('report_file');

        $medical = $this->general->record($type, $subject, $tenantId, $data, $user);

        // The doctor's next action is almost always to hand the certificate
        // over or print it, so it is rendered now rather than on demand.
        $this->pdf->tryStore($medical, [
            'name'    => $person['name'],
            'code'    => $person['email'] ?: $person['phone'],
            'context' => $person['context'],
            'label'   => GeneralMedical::SUBJECT_LABELS[$type] ?? null,
        ]);

        return response()->json([
            'message' => $medical->is_reexam ? 'Re-examination recorded.' : 'Examination recorded.',
            'data'    => $medical->fresh(),
        ], 201);
    }

    /**
     * The doctor's own examinations of one audience, newest first.
     *
     * The vendor sides have had this since the portal existed; these three did
     * not, so "My examinations" 404ed the moment a doctor switched to internal
     * staff, clients or visitors. The register was write-only from the doctor's
     * side too — they could file an examination and never see it again.
     */
    public function examinations(Request $request, string $audience)
    {
        $type     = $this->typeFor($audience);
        $user     = $request->user();
        $tenantId = $user->tenant_id;

        $rows = GeneralMedical::forTenant($tenantId)
            ->where('subject_type', $type)
            ->where('doctor_user_id', $user->id)
            ->when($request->query('qc_status'), fn ($q, $status) => $q->where('qc_status', $status))
            ->orderByDesc('exam_date')->orderByDesc('id')
            ->limit(500)->get();

        $names = $this->general->namesFor(
            $type,
            $rows->pluck('subject_id')->map(fn ($id) => (int) $id)->unique()->all(),
            $tenantId,
        );

        return response()->json([
            'data'     => $rows->map(fn ($r) => $this->withPerson($r, $names))->all(),
            'statuses' => MedicalQcStatus::ALL,
        ]);
    }

    /** One examination of this audience, as the drawer reads it. */
    public function examination(Request $request, string $audience, int $medical)
    {
        $type     = $this->typeFor($audience);
        $tenantId = $request->user()->tenant_id;

        $record = GeneralMedical::forTenant($tenantId)->where('subject_type', $type)->find($medical);
        abort_unless($record, 404, 'Examination not found.');
        abort_unless((int) $record->doctor_user_id === (int) $request->user()->id, 404);

        $names = $this->general->namesFor($type, [(int) $record->subject_id], $tenantId);

        return response()->json([
            'data' => [
                'medical'  => $this->withPerson($record, $names),
                // No thread: the back-and-forth resubmission flow belongs to the
                // vendor registers, and inventing an empty one here would put a
                // conversation box on a screen that has no conversation.
                'timeline' => null,
                'findings' => MedicalFindings::of($record->attributesToArray()),
            ],
        ]);
    }

    /**
     * Name the person on an examination.
     *
     * Shaped as `worker` because the detail drawer is shared with the two
     * vendor registers and that is what it reads. Giving these records a second
     * shape would mean a second drawer, and two drawers drift.
     *
     * @param  array<int, array{name:string,context:?string}>  $names
     */
    private function withPerson(GeneralMedical $record, array $names): array
    {
        $person = $names[(int) $record->subject_id] ?? null;

        return $record->toArray() + [
            'worker' => [
                'name'        => $person['name'] ?? 'Unknown',
                'worker_code' => $person['context'] ?? null,
            ],
        ];
    }

    /** The prescription as a PDF. */
    public function certificate(Request $request, string $audience, int $medical)
    {
        $type = $this->typeFor($audience);

        $record = GeneralMedical::forTenant($request->user()->tenant_id)
            ->where('subject_type', $type)->find($medical);
        abort_unless($record, 404, 'Examination not found.');

        $person = $this->general->describe($type, (int) $record->subject_id, $request->user()->tenant_id) ?? [];

        $path = $record->pdf_path && Storage::disk('local')->exists($record->pdf_path)
            ? $record->pdf_path
            : $this->pdf->store($record, [
                'name'    => $person['name'] ?? 'Unknown',
                'code'    => $person['email'] ?? null,
                'context' => $person['context'] ?? null,
                'label'   => GeneralMedical::SUBJECT_LABELS[$type] ?? null,
            ]);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.($record->certificate_no ?: 'certificate').'.pdf"',
        ]);
    }

    /* ── Internals ──────────────────────────────────────────────────────── */

    private function typeFor(string $audience): string
    {
        abort_unless(isset(self::AUDIENCES[$audience]), 404, 'Unknown audience.');

        return self::AUDIENCES[$audience];
    }
}
