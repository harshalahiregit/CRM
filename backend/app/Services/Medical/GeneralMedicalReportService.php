<?php

namespace App\Services\Medical;

use App\Models\Medical\GeneralMedical;
use App\Support\Medical\HealthScore;
use App\Support\Medical\MedicalFindings;
use App\Support\Medical\MedicalQcStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reporting over the general register — internal staff, client contacts and
 * site visitors.
 *
 * The doctor portal has been able to examine these three audiences, but nothing
 * could READ what it recorded: no register, no report, no way for an admin to
 * open one person's examination. Examinations were being filed into a table
 * nobody could see, which is worse than not recording them, because it looks
 * like a record and behaves like a drawer nobody has a key to.
 *
 * Kept separate from MedicalReportService rather than folded into it. That one
 * is built around vendors and workers — every aggregate is by vendor, by
 * project, by worker — and the three audiences here have none of those. Sharing
 * the class would mean a vendor dimension that is meaningless three times out
 * of five.
 *
 * The findings breakdown is the part that is genuinely new: not "how many
 * examinations" but "what was actually WRONG, and with how many people".
 */
class GeneralMedicalReportService
{
    /** URL segment ⇄ the subject_type stored on the record. */
    public const AUDIENCES = [
        'internal' => GeneralMedical::SUBJECT_USER,
        'client'   => GeneralMedical::SUBJECT_CLIENT,
        'visitor'  => GeneralMedical::SUBJECT_VISITOR,
    ];

    public const AUDIENCE_LABELS = [
        'internal' => 'Internal team',
        'client'   => 'Client contacts',
        'visitor'  => 'Site visitors',
    ];

    /** A page of the register. Large enough to be worth a table, small enough to render. */
    private const PAGE = 50;

    public function __construct(private GeneralMedicalService $general) {}

    /* ── The register ───────────────────────────────────────────────────── */

    /**
     * Examinations, newest first, with the person named on every row.
     *
     * @param  array{audience?:?string,fitness?:?string,qc_status?:?string,doctor_id?:mixed,from?:?string,to?:?string,q?:?string,page?:mixed}  $filters
     */
    public function register(int $tenantId, array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));

        $query = $this->query($tenantId, $filters);
        $total = (clone $query)->count();

        $rows = $query->with('doctor:id,name')
            ->orderByDesc('exam_date')->orderByDesc('id')
            ->forPage($page, self::PAGE)->get();

        return [
            'data' => $this->withPeople($rows, $tenantId)->map(fn ($r) => $this->row($r))->values()->all(),
            'meta' => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => self::PAGE,
                'pages'    => (int) ceil($total / self::PAGE),
            ],
        ];
    }

    /**
     * One examination, in full, with the person and their history beside it.
     *
     * The individual view the brief asked for: an admin looking at a certificate
     * needs to see what was found, who found it, what proved they were there,
     * and what the previous examinations said — on one screen.
     */
    public function one(int $tenantId, int $id): ?array
    {
        $record = GeneralMedical::forTenant($tenantId)->with('doctor:id,name,email')->find($id);

        if (! $record) {
            return null;
        }

        $audience = $this->audienceOf($record->subject_type);
        $person   = $this->general->describe($record->subject_type, (int) $record->subject_id, $tenantId);

        return [
            'medical'  => $record->toArray(),
            'person'   => ($person ?? ['id' => $record->subject_id, 'name' => 'Unknown']) + [
                'audience'       => $audience,
                'audience_label' => self::AUDIENCE_LABELS[$audience] ?? $audience,
            ],
            // Derived on read; see MedicalFindings for why nothing is stored.
            'findings' => MedicalFindings::of($record->attributesToArray()),
            'history'  => collect($this->general->history($record->subject_type, (int) $record->subject_id, $tenantId))
                ->map(fn ($h) => [
                    'id'             => $h->id,
                    'exam_date'      => optional($h->exam_date)->toDateString(),
                    'fitness_status' => $h->fitness_status,
                    'qc_status'      => $h->qc_status,
                    'health_score'   => $h->health_score,
                    'health_band'    => HealthScore::band($h->health_score),
                    'certificate_no' => $h->certificate_no,
                    'doctor'         => $h->doctor?->name,
                    'is_current'     => $h->id === $record->id,
                ])->values()->all(),
        ];
    }

    /* ── The report ─────────────────────────────────────────────────────── */

    /**
     * @param  array{audience?:?string,from?:?string,to?:?string,doctor_id?:mixed}  $filters
     */
    public function build(int $tenantId, array $filters = []): array
    {
        $rows = $this->withPeople(
            $this->query($tenantId, $filters)->with('doctor:id,name')->limit(20000)->get(),
            $tenantId,
        );

        return [
            'generated_at' => now()->toDateTimeString(),
            'filters'      => [
                'audience'  => $filters['audience'] ?? null,
                'from'      => $filters['from'] ?? null,
                'to'        => $filters['to'] ?? null,
                'doctor_id' => $filters['doctor_id'] ?? null,
            ],
            'totals'      => $this->totals($rows),
            'by_audience' => $this->byAudience($rows),
            'health'      => $this->health($rows),
            'by_month'    => $this->byMonth($rows),
            'by_doctor'   => $this->byDoctor($rows),
            'findings'    => $this->findings($rows),
            'people'      => $this->byPerson($rows),
        ];
    }

    /* ── Reading ────────────────────────────────────────────────────────── */

    private function query(int $tenantId, array $filters)
    {
        $query = GeneralMedical::forTenant($tenantId);

        if ($audience = ($filters['audience'] ?? null)) {
            // An unknown audience matches the empty set rather than everything:
            // a typo in a filter must never widen what comes back.
            $query->where('subject_type', self::AUDIENCES[$audience] ?? '__none__');
        }

        $query
            ->when($filters['fitness'] ?? null, fn ($q, $v) => $q->where('fitness_status', $v))
            ->when($filters['qc_status'] ?? null, fn ($q, $v) => $q->where('qc_status', $v))
            ->when($filters['doctor_id'] ?? null, fn ($q, $v) => $q->where('doctor_user_id', (int) $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('exam_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('exam_date', '<=', $v));

        // Searching by the person's name, in SQL. The name is in one of three
        // other tables, so the ids are resolved first and matched here — rather
        // than filtering the page after it was fetched, which would give short
        // pages and a total that disagrees with them.
        if ($search = trim((string) ($filters['q'] ?? ''))) {
            $query->where(function ($outer) use ($search, $tenantId, $filters) {
                $outer->where('certificate_no', 'like', "%{$search}%");

                $types = ($audience = ($filters['audience'] ?? null))
                    ? array_filter([self::AUDIENCES[$audience] ?? null])
                    : array_values(self::AUDIENCES);

                foreach ($types as $type) {
                    $ids = $this->general->subjectIdsMatching($type, $tenantId, $search);

                    if ($ids) {
                        $outer->orWhere(fn ($w) => $w->where('subject_type', $type)->whereIn('subject_id', $ids));
                    }
                }
            });
        }

        return $query;
    }

    /**
     * Attach each person's name to their examinations.
     *
     * Grouped by subject type first so this is three queries whatever the page
     * size, rather than one per row.
     */
    private function withPeople(Collection $rows, int $tenantId): Collection
    {
        $names = [];

        foreach ($rows->groupBy('subject_type') as $type => $group) {
            $names[$type] = $this->general->namesFor(
                (string) $type,
                $group->pluck('subject_id')->map(fn ($id) => (int) $id)->unique()->all(),
                $tenantId,
            );
        }

        return $rows->each(function ($r) use ($names) {
            $person = $names[$r->subject_type][(int) $r->subject_id] ?? null;
            // Set on the model rather than mapped away, because the aggregates
            // below and the register rows both need it.
            $r->setAttribute('person_name', $person['name'] ?? 'Unknown');
            $r->setAttribute('person_context', $person['context'] ?? null);
        });
    }

    private function row($r): array
    {
        return [
            'id'             => $r->id,
            'certificate_no' => $r->certificate_no,
            'audience'       => $this->audienceOf($r->subject_type),
            'audience_label' => self::AUDIENCE_LABELS[$this->audienceOf($r->subject_type)] ?? $r->subject_type,
            'person'         => $r->person_name,
            'context'        => $r->person_context,
            'exam_date'      => optional($r->exam_date)->toDateString(),
            'valid_until'    => optional($r->valid_until)->toDateString(),
            'fitness_status' => $r->fitness_status,
            'qc_status'      => $r->qc_status,
            'health_score'   => $r->health_score,
            'health_band'    => HealthScore::band($r->health_score !== null ? (float) $r->health_score : null),
            'doctor'         => $r->doctor?->name ?: $r->examiner_name,
            'is_expired'     => $r->isExpired(),
            'is_reexam'      => (bool) $r->is_reexam,
            // How many things were found, so a register row can be triaged
            // without opening it.
            'finding_count'  => count(MedicalFindings::of($r->attributesToArray())),
        ];
    }

    private function audienceOf(?string $subjectType): string
    {
        return array_search($subjectType, self::AUDIENCES, true) ?: (string) $subjectType;
    }

    /* ── Aggregates ─────────────────────────────────────────────────────── */

    private function totals(Collection $rows): array
    {
        return [
            'examinations' => $rows->count(),
            'people'       => $rows->map(fn ($r) => $r->subject_type.':'.$r->subject_id)->unique()->count(),
            'passing'      => $rows->filter(fn ($r) => $r->isPassing())->count(),
            'expired'      => $rows->filter(fn ($r) => $r->isExpired())->count(),
            'pending'      => $rows->where('qc_status', MedicalQcStatus::PENDING)->count(),
            'approved'     => $rows->where('qc_status', MedicalQcStatus::APPROVED)->count(),
            'held'         => $rows->where('qc_status', MedicalQcStatus::HOLD)->count(),
            'rejected'     => $rows->where('qc_status', MedicalQcStatus::REJECTED)->count(),
        ];
    }

    private function byAudience(Collection $rows): array
    {
        return collect(self::AUDIENCES)->map(fn ($type, $key) => [
            'audience'     => $key,
            'label'        => self::AUDIENCE_LABELS[$key],
            'examinations' => $rows->where('subject_type', $type)->count(),
            'people'       => $rows->where('subject_type', $type)->pluck('subject_id')->unique()->count(),
            'passing'      => $rows->where('subject_type', $type)->filter(fn ($r) => $r->isPassing())->count(),
        ])->values()->all();
    }

    private function health(Collection $rows): array
    {
        $scored = $rows->filter(fn ($r) => $r->health_score !== null);

        $bands = ['Excellent' => 0, 'Good' => 0, 'Fair' => 0, 'Poor' => 0];
        foreach ($scored as $r) {
            $band = HealthScore::band((float) $r->health_score);
            if ($band !== null) {
                $bands[$band]++;
            }
        }

        return [
            'scored'  => $scored->count(),
            'average' => $scored->count() ? round($scored->avg('health_score'), 1) : null,
            'bands'   => collect($bands)->map(fn ($count, $band) => ['band' => $band, 'count' => $count])->values()->all(),
        ];
    }

    private function byMonth(Collection $rows): array
    {
        return $rows->filter(fn ($r) => $r->exam_date)
            ->groupBy(fn ($r) => Carbon::parse($r->exam_date)->format('Y-m'))
            ->map(fn ($group, $month) => [
                'month'        => $month,
                'label'        => Carbon::createFromFormat('Y-m', $month)->format('M Y'),
                'examinations' => $group->count(),
                'passing'      => $group->filter(fn ($r) => $r->isPassing())->count(),
            ])
            ->sortKeys()->values()->all();
    }

    private function byDoctor(Collection $rows): array
    {
        return $rows->groupBy(fn ($r) => $r->doctor?->name ?: ($r->examiner_name ?: 'Unattributed'))
            ->map(fn ($group, $name) => [
                'doctor'       => $name,
                'examinations' => $group->count(),
                'passing'      => $group->filter(fn ($r) => $r->isPassing())->count(),
                'average'      => $group->filter(fn ($r) => $r->health_score !== null)->count()
                    ? round($group->whereNotNull('health_score')->avg('health_score'), 1)
                    : null,
            ])
            ->sortByDesc('examinations')->values()->all();
    }

    /**
     * What was actually found, and with how many people.
     *
     * The question a report over medicals should answer and normally does not.
     * "412 examinations, 380 passing" says nothing an admin can act on; "nine
     * people share raised blood pressure, six of them internal staff" does.
     */
    private function findings(Collection $rows): array
    {
        // One examination per person — the most recent. Counting every
        // examination would make somebody seen quarterly look like four people
        // with the same condition.
        $latest = $rows->sortBy('exam_date')
            ->keyBy(fn ($r) => $r->subject_type.':'.$r->subject_id);

        $grouped = MedicalFindings::group($latest->map(fn ($r) => [
            'id'      => $r->id,
            'name'    => $r->person_name,
            'context' => $r->person_context,
            'exam'    => $r->attributesToArray(),
        ])->values()->all());

        return $grouped;
    }

    /**
     * Every person, with their standing — the individual view in list form.
     *
     * "Admin can see individually" needs a way IN as well as a detail page, and
     * a report of totals gives you no door to a person.
     */
    private function byPerson(Collection $rows): array
    {
        return $rows->groupBy(fn ($r) => $r->subject_type.':'.$r->subject_id)
            ->map(function ($group) {
                $latest = $group->sortByDesc('exam_date')->first();

                return [
                    'subject_type'   => $latest->subject_type,
                    'audience'       => $this->audienceOf($latest->subject_type),
                    'subject_id'     => (int) $latest->subject_id,
                    'name'           => $latest->person_name,
                    'context'        => $latest->person_context,
                    'examinations'   => $group->count(),
                    'latest_id'      => $latest->id,
                    'last_exam'      => optional($latest->exam_date)->toDateString(),
                    'valid_until'    => optional($latest->valid_until)->toDateString(),
                    'fitness_status' => $latest->fitness_status,
                    'qc_status'      => $latest->qc_status,
                    'health_score'   => $latest->health_score,
                    'health_band'    => HealthScore::band($latest->health_score !== null ? (float) $latest->health_score : null),
                    'is_expired'     => $latest->isExpired(),
                    'findings'       => MedicalFindings::of($latest->attributesToArray()),
                ];
            })
            ->sortBy('name')->values()->all();
    }
}
