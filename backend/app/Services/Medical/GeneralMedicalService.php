<?php

namespace App\Services\Medical;

use App\Models\Customer\ClientContact;
use App\Models\Medical\GeneralMedical;
use App\Models\Medical\MedicalVisitor;
use App\Models\User;
use App\Support\Medical\HealthScore;
use App\Support\Medical\MedicalQcStatus;
use App\Support\Medical\MedicalWorkflow;
use App\Support\Tpv\TpvMedicalFitness as Fitness;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Examinations of everyone who is not a vendor worker.
 *
 * Internal team members, a client's people, and site visitors. The rules are
 * deliberately the same ones the two vendor registers already use — the same
 * validity window, the same health score, the same quality-check vocabulary —
 * because "medically cleared" cannot mean one thing for a contractor and
 * another for the employee standing beside them.
 *
 * What is deliberately NOT here: bulk external upload and the vendor
 * back-and-forth timeline. Those exist because a vendor submits certificates on
 * behalf of a workforce it manages. Nobody submits on behalf of an employee or
 * a visitor — the doctor examines them directly — so building the machinery for
 * a conversation that has no second party would be scaffolding around nothing.
 */
class GeneralMedicalService
{
    public function __construct(private MedicalGeoResolver $geo) {}

    /** How long a certificate stays current, in months. */
    private const VALIDITY_MONTHS = 12;

    /**
     * How many people a search returns at once.
     *
     * Small on purpose, and the total is always reported alongside, so a screen
     * can say how much it is not showing rather than drawing a list that
     * silently stops.
     */
    private const PAGE = 50;

    /* ── Who can be examined ────────────────────────────────────────────── */

    /**
     * The people of one audience, searchable.
     *
     * Returns a uniform shape whatever the audience, so one picker screen can
     * serve all of them — which is the whole point of a portal that is supposed
     * to serve everybody.
     */
    public function subjects(string $type, int $tenantId, ?string $search = null, ?int $limit = null): array
    {
        $limit ??= self::PAGE;
        $like = $search ? '%'.trim($search).'%' : null;

        return match ($type) {
            GeneralMedical::SUBJECT_USER => User::where('tenant_id', $tenantId)
                ->where('status', 'active')
                // Staff and admins — the internal team. Portal accounts belong
                // to their own registers and are examined there.
                ->whereIn('role', ['admin', 'staff'])
                ->when($like, fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)))
                ->orderBy('name')->limit($limit)
                ->get(['id', 'name', 'email', 'phone', 'department', 'internal_role'])
                ->map(fn ($u) => $this->shape($u->id, $u->name, $u->email, $u->phone, $u->department ?: $u->internal_role))
                ->all(),

            GeneralMedical::SUBJECT_CLIENT => ClientContact::where('tenant_id', $tenantId)
                ->where('active', true)
                ->when($like, fn ($q) => $q->where(fn ($w) => $w->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)->orWhere('email', 'like', $like)))
                ->with('client:id,company')
                ->orderBy('first_name')->limit($limit)->get()
                ->map(fn ($c) => $this->shape(
                    $c->id,
                    trim($c->first_name.' '.$c->last_name) ?: 'Unnamed contact',
                    $c->email, $c->phone, $c->client?->company,
                ))->all(),

            GeneralMedical::SUBJECT_VISITOR => MedicalVisitor::forTenant($tenantId)
                ->when($like, fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', $like)
                    ->orWhere('phone', 'like', $like)->orWhere('company', 'like', $like)))
                ->orderByDesc('id')->limit($limit)->get()
                ->map(fn ($v) => $this->shape($v->id, $v->name, $v->email, $v->phone, $v->company, $v->purpose))
                ->all(),

            default => [],
        };
    }

    /**
     * One person's name and context, or null when the id does not resolve.
     *
     * A direct lookup. This used to list up to a hundred thousand people and
     * then search that array in PHP for one id — which is a table scan, a large
     * allocation and a slow request, to answer a question the database answers
     * with a primary-key hit.
     */
    public function describe(string $type, int $id, int $tenantId): ?array
    {
        return match ($type) {
            GeneralMedical::SUBJECT_USER => ($u = User::where('tenant_id', $tenantId)
                ->whereIn('role', ['admin', 'staff'])->find($id))
                    ? $this->shape($u->id, $u->name, $u->email, $u->phone, $u->department ?: $u->internal_role)
                    : null,

            GeneralMedical::SUBJECT_CLIENT => ($c = ClientContact::where('tenant_id', $tenantId)
                ->with('client:id,company')->find($id))
                    ? $this->shape($c->id, trim($c->first_name.' '.$c->last_name) ?: 'Unnamed contact',
                        $c->email, $c->phone, $c->client?->company)
                    : null,

            GeneralMedical::SUBJECT_VISITOR => ($v = MedicalVisitor::forTenant($tenantId)->find($id))
                ? $this->shape($v->id, $v->name, $v->email, $v->phone, $v->company, $v->purpose)
                : null,

            default => null,
        };
    }

    /** How many people match, so a screen can say what it is not showing. */
    public function countSubjects(string $type, int $tenantId, ?string $search = null): int
    {
        $like = $search ? '%'.trim($search).'%' : null;

        return match ($type) {
            GeneralMedical::SUBJECT_USER => User::where('tenant_id', $tenantId)
                ->where('status', 'active')->whereIn('role', ['admin', 'staff'])
                ->when($like, fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)))
                ->count(),

            GeneralMedical::SUBJECT_CLIENT => ClientContact::where('tenant_id', $tenantId)->where('active', true)
                ->when($like, fn ($q) => $q->where(fn ($w) => $w->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)->orWhere('email', 'like', $like)))
                ->count(),

            GeneralMedical::SUBJECT_VISITOR => MedicalVisitor::forTenant($tenantId)
                ->when($like, fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', $like)
                    ->orWhere('phone', 'like', $like)->orWhere('company', 'like', $like)))
                ->count(),

            default => 0,
        };
    }

    /** A walk-in the system has never met. */
    public function createVisitor(int $tenantId, array $data, User $actor): MedicalVisitor
    {
        return MedicalVisitor::create([
            'tenant_id'       => $tenantId,
            'name'            => $data['name'],
            'phone'           => $data['phone'] ?? null,
            'email'           => $data['email'] ?? null,
            'company'         => $data['company'] ?? null,
            'id_proof_type'   => $data['id_proof_type'] ?? null,
            'id_proof_number' => $data['id_proof_number'] ?? null,
            'purpose'         => $data['purpose'] ?? null,
            'created_by'      => $actor->id,
        ]);
    }

    /* ── Recording ──────────────────────────────────────────────────────── */

    public function record(string $type, int $subjectId, int $tenantId, array $data, User $doctor, ?GeneralMedical $previous = null): GeneralMedical
    {
        $isReexam = (bool) ($data['is_reexam'] ?? false) || $previous !== null;
        $values   = $this->prepare($type, $subjectId, $tenantId, $data, $doctor);

        $existing = GeneralMedical::forTenant($tenantId)->forSubject($type, $subjectId)
            ->whereDate('exam_date', $values['exam_date'])
            ->orderByDesc('attempt_no')->first();

        // Same person, same day, still unreviewed and not a re-examination:
        // this is a correction to what was just filed, not a second visit.
        $reuse = $existing && ! $isReexam
            && ($existing->qc_status === null || $existing->qc_status === MedicalQcStatus::PENDING);

        return DB::transaction(function () use ($type, $subjectId, $tenantId, $values, $existing, $reuse, $isReexam, $previous) {
            if ($reuse) {
                $existing->update($values);
                $medical = $existing->fresh();
            } else {
                $values['attempt_no'] = $existing ? ((int) $existing->attempt_no + 1) : 1;
                if ($isReexam) {
                    $values['is_reexam'] = true;
                    $values['previous_medical_id'] = $previous?->id
                        ?? GeneralMedical::forTenant($tenantId)->forSubject($type, $subjectId)
                            ->orderByDesc('exam_date')->orderByDesc('attempt_no')->first()?->id;
                }
                $medical = GeneralMedical::create($values);
            }

            if (blank($medical->certificate_no)) {
                $medical->certificate_no = $this->certificateNumber($medical);
                $medical->save();
            }

            return $medical->fresh();
        });
    }

    private function prepare(string $type, int $subjectId, int $tenantId, array $data, User $doctor): array
    {
        // Columns the caller must never be able to set directly: they are the
        // proof the certificate rests on, and a request that could supply its
        // own IP, place or quality verdict could forge one.
        unset($data['signature_path'], $data['capture_photo_path'], $data['pdf_path'],
              $data['certificate_no'], $data['qc_status'], $data['qc_by'], $data['qc_at'],
              $data['iteration_count'], $data['system_ip'], $data['geo_place'],
              $data['subject_type'], $data['subject_id'], $data['tenant_id']);

        $data['exam_date'] = Carbon::parse($data['exam_date'] ?? now())->toDateString();
        $data['valid_until'] ??= Carbon::parse($data['exam_date'])->addMonths(self::VALIDITY_MONTHS)->toDateString();

        if (($data['report_file'] ?? null) instanceof UploadedFile) {
            $data['document_path'] = $data['report_file']->store("medical/general/{$tenantId}/{$type}/{$subjectId}", 'local');
        }
        unset($data['report_file']);

        foreach ([
            'signature_data' => ['signature_path', 'medical/general/signatures/sig_'],
            'capture_photo'  => ['capture_photo_path', 'medical/general/photos/photo_'],
        ] as $src => [$column, $prefix]) {
            if ($stored = $this->storeDataUrl($data[$src] ?? null, $prefix)) {
                $data[$column] = $stored;
            }
            unset($data[$src]);
        }

        // Taken from the request, never from the payload — see above.
        $data['system_ip'] = request()?->ip();
        if (! empty($data['geo_location'])) {
            $data['geo_place'] = $this->geo->resolve($data['geo_location']);
        }

        $profile = $doctor->doctorProfile;
        $data['doctor_user_id']       = $doctor->id;
        $data['doctor_license_no']    = $profile?->license_no;
        $data['doctor_council']       = $profile?->council;
        $data['doctor_qualification'] = $profile?->qualification;
        $data['examiner_name']      ??= $doctor->name;
        $data['clinic_name']        ??= $profile?->clinic_name;
        $data['exam_type']          ??= 'internal';

        if (($data['health_score'] ?? null) !== null && $data['health_score'] !== '') {
            $data['health_score']        = round((float) $data['health_score'], 1);
            $data['health_score_source'] = 'manual';
        } else {
            $computed = HealthScore::compute($data + ['fitness_status' => $data['fitness_status'] ?? null]);
            $data['health_score']        = $computed['score'];
            $data['health_score_source'] = 'auto';
        }

        return [
            ...$data,
            'tenant_id'    => $tenantId,
            'subject_type' => $type,
            'subject_id'   => $subjectId,
            'recorded_by'  => $doctor->id,
            'created_by'   => $doctor->id,
            'origin'       => MedicalWorkflow::ORIGIN_DOCTOR_PORTAL,
            'qc_status'    => MedicalQcStatus::PENDING,
        ];
    }

    /* ── Reading ────────────────────────────────────────────────────────── */

    /**
     * One person's examinations, newest first.
     *
     * Capped, because this feeds a timeline on a profile page — somebody
     * examined monthly for a decade should not send 120 records to a screen
     * that shows the recent ones.
     */
    public function history(string $type, int $subjectId, int $tenantId, int $limit = 10): array
    {
        return GeneralMedical::forTenant($tenantId)->forSubject($type, $subjectId)
            ->with('doctor:id,name')
            ->orderByDesc('exam_date')->orderByDesc('attempt_no')
            ->limit($limit)->get()->all();
    }

    /**
     * The ids of everyone in one audience whose name matches a search.
     *
     * For filtering the admin register by person. The name lives in one of
     * three other tables, so it cannot be a WHERE clause on the examination —
     * but resolving the ids first and matching on those keeps the filter in
     * SQL, which is what pagination needs. Filtering the page AFTER it was
     * fetched would give short pages and a wrong total.
     *
     * @return list<int>
     */
    public function subjectIdsMatching(string $type, int $tenantId, string $search): array
    {
        $like = '%'.trim($search).'%';

        return match ($type) {
            GeneralMedical::SUBJECT_USER => User::where('tenant_id', $tenantId)
                ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like))
                ->limit(self::MATCH_CAP)->pluck('id')->all(),

            GeneralMedical::SUBJECT_CLIENT => ClientContact::where('tenant_id', $tenantId)
                ->where(fn ($w) => $w->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)->orWhere('email', 'like', $like))
                ->limit(self::MATCH_CAP)->pluck('id')->all(),

            GeneralMedical::SUBJECT_VISITOR => MedicalVisitor::forTenant($tenantId)
                ->where(fn ($w) => $w->where('name', 'like', $like)
                    ->orWhere('phone', 'like', $like)->orWhere('company', 'like', $like))
                ->limit(self::MATCH_CAP)->pluck('id')->all(),

            default => [],
        };
    }

    /** How many people a name search may resolve to before it stops being a search. */
    private const MATCH_CAP = 2000;

    /**
     * Names and context for many people of one audience, in one query.
     *
     * The admin register lists examinations, and an examination without the
     * name of the person it is of is unreadable. Resolving them one at a time
     * would be a query per row.
     *
     * @param  list<int>  $ids
     * @return array<int, array{name:string,context:?string}>
     */
    public function namesFor(string $type, array $ids, int $tenantId): array
    {
        if (! $ids) {
            return [];
        }

        $rows = match ($type) {
            GeneralMedical::SUBJECT_USER => User::where('tenant_id', $tenantId)->whereIn('id', $ids)
                ->get(['id', 'name', 'department', 'internal_role'])
                ->mapWithKeys(fn ($u) => [$u->id => ['name' => $u->name, 'context' => $u->department ?: $u->internal_role]]),

            GeneralMedical::SUBJECT_CLIENT => ClientContact::where('tenant_id', $tenantId)->whereIn('id', $ids)
                ->with('client:id,company')->get()
                ->mapWithKeys(fn ($c) => [$c->id => [
                    'name'    => trim($c->first_name.' '.$c->last_name) ?: 'Unnamed contact',
                    'context' => $c->client?->company,
                ]]),

            GeneralMedical::SUBJECT_VISITOR => MedicalVisitor::forTenant($tenantId)->whereIn('id', $ids)
                ->get(['id', 'name', 'company'])
                ->mapWithKeys(fn ($v) => [$v->id => ['name' => $v->name, 'context' => $v->company]]),

            default => collect(),
        };

        return $rows->all();
    }

    /**
     * The latest examination for each of many people, in one query.
     *
     * For reading a group together. Asking per person would be one query per
     * tick box, which for a session of forty is forty round trips to answer a
     * single screen.
     *
     * @param  list<int>  $ids
     * @return array<int, \App\Models\Medical\GeneralMedical>  keyed by subject id
     */
    public function latestFor(string $type, array $ids, int $tenantId): array
    {
        if (! $ids) {
            return [];
        }

        return GeneralMedical::forTenant($tenantId)
            ->where('subject_type', $type)
            ->whereIn('subject_id', $ids)
            ->orderBy('exam_date')->orderBy('attempt_no')
            ->get()
            // Ordered oldest first and keyed by subject, so the last write for
            // each person wins and the newest examination is what remains.
            ->keyBy('subject_id')
            ->all();
    }

    /** The single verdict everything else reads: is this person medically clear? */
    public function clearanceFor(string $type, int $subjectId, int $tenantId): array
    {
        $latest = GeneralMedical::forTenant($tenantId)->forSubject($type, $subjectId)
            ->orderByDesc('exam_date')->orderByDesc('attempt_no')->first();

        if (! $latest) {
            return ['cleared' => false, 'state' => 'none', 'message' => 'No medical examination on record.'];
        }

        return match (true) {
            $latest->isCurrentlyValid() => ['cleared' => true, 'state' => 'cleared', 'message' => 'Medically cleared.'],
            $latest->qc_status === MedicalQcStatus::REJECTED => [
                'cleared' => false, 'state' => 'rejected',
                'message' => 'Medical was rejected by the quality team. A re-examination is required.',
            ],
            $latest->isExpired() => ['cleared' => false, 'state' => 'expired', 'message' => 'Medical certificate has expired.'],
            ! $latest->isPassing() => ['cleared' => false, 'state' => 'unfit', 'message' => 'Assessed as '.Fitness::label($latest->fitness_status).'.'],
            default => ['cleared' => false, 'state' => 'pending', 'message' => 'Medical Report is Pending — awaiting quality check.'],
        };
    }

    /* ── Internals ──────────────────────────────────────────────────────── */

    private function shape($id, ?string $name, ?string $email, ?string $phone, ?string $context, ?string $note = null): array
    {
        return [
            'id'      => (int) $id,
            'name'    => $name ?: 'Unnamed',
            'email'   => $email,
            'phone'   => $phone,
            'context' => $context,
            'note'    => $note,
        ];
    }

    private function storeDataUrl(?string $dataUrl, string $prefix): ?string
    {
        if (! $dataUrl || ! str_contains($dataUrl, 'base64,')) {
            return null;
        }
        $binary = base64_decode(explode('base64,', $dataUrl)[1], true);
        if ($binary === false) {
            return null;
        }
        $path = $prefix.uniqid().'.png';
        Storage::disk('public')->put($path, $binary);

        return $path;
    }

    private function certificateNumber(GeneralMedical $medical): string
    {
        return sprintf('GM-%s-%06d', Carbon::parse($medical->exam_date)->format('Y'), $medical->id);
    }
}
