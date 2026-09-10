<?php

namespace Sire\Services;

use Sire\Exceptions\SireException;
use Sire\Models\IssueTestCase;
use Sire\Models\Report;
use Sire\Dto\SireUserIdentity;
use Sire\Support\SireStatus;
use Sire\Support\SireTestCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SIRE — test cases and their results.
 *
 * A CORE service. It knows nothing about AI: a generated test arrives here as an
 * ordinary array with `source: 'ai_suggested'`, exactly like one typed by hand
 * arrives with `source: 'human'`. That is why the AI layer needs no write path —
 * the client carries accepted suggestions to this endpoint, and a person is the
 * author of every row.
 *
 * THE INVARIANT THIS CLASS PROTECTS
 *
 *   `result` is written in exactly one method, record(), which requires a User.
 *   Creation cannot set it, updating cannot set it, and nothing in the AI layer
 *   can reach it. An AI-generated test that arrived "passed" would be worse than
 *   no test at all, so it is not possible to express.
 */
class SireTestCaseService
{
    /** Sanity bound on a single accept-all. */
    private const MAX_BULK = 50;

    public function __construct(private readonly SireAccessService $access)
    {
    }

    public function for(Report $report, ?string $phase = null): Collection
    {
        return IssueTestCase::query()
            ->forTenant($report->tenant_id)
            ->where('report_id', $report->id)
            ->where('status', '!=', IssueTestCase::REMOVED)
            ->when($phase, fn ($q, $v) => $q->whereIn('phase', [$v, IssueTestCase::PHASE_BOTH]))
            ->with(['executor:id,name', 'author:id,name'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Create one or many test cases.
     *
     * `$definitions` may come from a generator (carried by the client after a human
     * accepted them) or from a person typing. Either way the caller is the author,
     * and neither can set a result.
     *
     * @param  array<int, array<string, mixed>>  $definitions
     */
    public function create(Report $report, array $definitions, SireUserIdentity $actor, ?int $suggestionId = null): Collection
    {
        $this->access->assert($actor, 'sire.report.develop', $report);

        if (count($definitions) > self::MAX_BULK) {
            throw new SireException('Too many test cases at once. Add up to '.self::MAX_BULK.'.');
        }

        return DB::transaction(function () use ($report, $definitions, $actor, $suggestionId) {
            $next = (int) IssueTestCase::query()
                ->forTenant($report->tenant_id)
                ->where('report_id', $report->id)
                ->max('sort_order');

            $created = collect();

            foreach ($definitions as $definition) {
                if (! SireTestCategory::isValid($definition['category'] ?? null)) {
                    throw new SireException('Unknown test case category.');
                }

                $created->push(IssueTestCase::create([
                    'tenant_id'        => $report->tenant_id,   // explicit, never ambient
                    'report_id'        => $report->id,
                    'category'         => $definition['category'],
                    'title'            => $definition['title'],
                    'given'            => $definition['given'] ?? null,
                    'when'             => $definition['when'] ?? null,
                    'then'             => $definition['then'] ?? null,
                    'rationale'        => $definition['rationale'] ?? null,
                    // Recorded honestly. A test a human accepted from a generator is
                    // still a generated test, and the register should say so.
                    'source'           => ($definition['source'] ?? null) === IssueTestCase::SOURCE_AI
                        ? IssueTestCase::SOURCE_AI
                        : IssueTestCase::SOURCE_HUMAN,
                    'ai_suggestion_id' => $suggestionId,
                    'phase'            => in_array($definition['phase'] ?? null, IssueTestCase::PHASES, true)
                        ? $definition['phase']
                        : IssueTestCase::PHASE_BOTH,
                    // Accepted means active. Nothing arrives here still a draft.
                    'status'           => IssueTestCase::ACTIVE,
                    // NOT settable. Whatever the payload said, a new test is unrun.
                    'result'           => null,
                    'created_by'       => $actor->id,
                    'sort_order'       => ++$next,
                ]));
            }

            $report->recordAudit(
                sprintf('%d test case(s) added', $created->count()),
                $actor,
                null,
                [
                    'action' => 'test_cases_added',
                    'system' => true,
                    'source' => $suggestionId ? 'ai_suggested' : 'human',
                    'ai_suggestion_id' => $suggestionId,
                ],
            );

            return $created;
        });
    }

    /** Edit the wording. Cannot touch the result — that is record()'s alone. */
    public function update(IssueTestCase $testCase, array $data, SireUserIdentity $actor): IssueTestCase
    {
        $this->access->assert($actor, 'sire.report.develop', $testCase->report);

        $testCase->fill(array_intersect_key($data, array_flip([
            'title', 'given', 'when', 'then', 'rationale', 'phase', 'category', 'sort_order',
        ])))->save();

        return $testCase;
    }

    /**
     * Remove a test from the checklist without destroying the record of it having
     * been proposed. A generated test somebody rejected is useful feedback, and
     * deleting the row would throw that away.
     */
    public function remove(IssueTestCase $testCase, SireUserIdentity $actor, ?string $reason = null): IssueTestCase
    {
        $this->access->assert($actor, 'sire.report.develop', $testCase->report);

        $testCase->fill(['status' => IssueTestCase::REMOVED])->save();
        $testCase->recordAudit('Test case removed', $actor, $reason, ['system' => true]);

        return $testCase;
    }

    /**
     * Record a result. THE ONLY PLACE `result` IS EVER WRITTEN.
     *
     * Requires a human actor and the QA capability. There is no automatic pass, no
     * inferred pass, and no bulk "mark all passed" — each result is one person
     * saying they ran one test.
     */
    public function record(IssueTestCase $testCase, string $result, SireUserIdentity $actor, ?string $note = null): IssueTestCase
    {
        if (! in_array($result, IssueTestCase::RESULTS, true)) {
            throw new SireException('A test result must be passed, failed, blocked or skipped.');
        }

        $this->access->assert($actor, 'sire.qa.execute', $testCase->report);

        if ($testCase->status !== IssueTestCase::ACTIVE) {
            throw new SireException('That test case is not active.');
        }

        $report = $testCase->report;

        // Results belong to a QA run. Recording one against an issue that is not in
        // QA is how a checklist ends up green before anyone looked at the build.
        if ($report && ! in_array($report->status, [SireStatus::QA_IN_PROGRESS, SireStatus::IN_DEVELOPMENT], true)) {
            throw new SireException(
                'Results can be recorded while an issue is in development or QA. This one is '
                .SireStatus::label((string) $report->status).'.',
            );
        }

        return DB::transaction(function () use ($testCase, $result, $actor, $note, $report) {
            $testCase->fill([
                'result'      => $result,
                'executed_by' => $actor->id,
                'executed_at' => now(),
                'result_note' => $note,
            ])->save();

            $testCase->recordAudit("Test case {$result}", $actor, $note, [
                'action' => 'test_result', 'result' => $result, 'system' => true,
            ]);

            return $testCase->fresh();
        });
    }

    /** Reset a result so a test can be re-run after a fix. Audited, never silent. */
    public function reset(IssueTestCase $testCase, SireUserIdentity $actor): IssueTestCase
    {
        $this->access->assert($actor, 'sire.qa.execute', $testCase->report);

        $previous = $testCase->result;

        $testCase->fill(['result' => null, 'executed_by' => null, 'executed_at' => null, 'result_note' => null])->save();
        $testCase->recordAudit('Test case reset for re-run', $actor, null, [
            'action' => 'test_reset', 'previous_result' => $previous, 'system' => true,
        ]);

        return $testCase;
    }

    /** Counts for the QA panel: what is left, what failed, what nobody ran. */
    public function summary(Report $report): array
    {
        $cases = $this->for($report);

        return [
            'total'   => $cases->count(),
            'passed'  => $cases->where('result', IssueTestCase::PASSED)->count(),
            'failed'  => $cases->where('result', IssueTestCase::FAILED)->count(),
            'blocked' => $cases->where('result', IssueTestCase::BLOCKED)->count(),
            'skipped' => $cases->where('result', IssueTestCase::SKIPPED)->count(),
            // The number QA actually cares about.
            'unrun'   => $cases->whereNull('result')->count(),
            'generated' => $cases->where('source', IssueTestCase::SOURCE_AI)->count(),
        ];
    }
}
