<?php

namespace Sire\Http\Controllers;

use Sire\Http\Controllers\Concerns\AssertsSireTenantOwnership;
use Sire\Http\Controllers\Concerns\ResolvesSireUser;
use Sire\Http\Controllers\Concerns\SireApiResponse;
use Sire\Http\Requests\StoreTestCasesRequest;
use Sire\Models\IssueTestCase;
use Sire\Models\Report;
use Sire\Services\SireTestCaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * SIRE — test cases. A CORE controller that knows nothing about AI.
 *
 * Generated tests reach this endpoint the same way hand-written ones do: the
 * client posts definitions, a person is recorded as the author, and no result can
 * be set. That is why the AI layer needs no write path — the client is the
 * courier, and the human is the author.
 */
class SireTestCaseController extends SireController
{
    use SireApiResponse;
    use ResolvesSireUser;
    use AssertsSireTenantOwnership;

    public function __construct(private readonly SireTestCaseService $testCases)
    {
    }

    public function index(Request $request, Report $report): JsonResponse
    {
        $this->assertTenantOwnership($report);

        $phase = $request->query('phase');

        return $this->success([
            'test_cases' => $this->testCases->for($report, in_array($phase, IssueTestCase::PHASES, true) ? $phase : null),
            'summary'    => $this->testCases->summary($report),
        ]);
    }

    /** Add one or many. Accepts generated definitions and hand-written ones alike. */
    public function store(StoreTestCasesRequest $request, Report $report): JsonResponse
    {
        $this->assertTenantOwnership($report);

        return $this->success(
            $this->testCases->create(
                $report,
                $request->validated('test_cases'),
                $this->sireUser(),
                $request->validated('ai_suggestion_id'),
            ),
            201,
        );
    }

    public function update(Request $request, IssueTestCase $testCase): JsonResponse
    {
        $this->assertTenantOwnership($testCase);

        $data = $request->validate([
            'title'      => ['sometimes', 'string', 'max:255'],
            'given'      => ['nullable', 'string', 'max:5000'],
            'when'       => ['nullable', 'string', 'max:5000'],
            'then'       => ['nullable', 'string', 'max:5000'],
            'rationale'  => ['nullable', 'string', 'max:1000'],
            'phase'      => ['nullable', Rule::in(IssueTestCase::PHASES)],
            'sort_order' => ['nullable', 'integer', 'between:0,9999'],
        ]);

        return $this->success($this->testCases->update($testCase, $data, $this->sireUser()));
    }

    public function destroy(Request $request, IssueTestCase $testCase): JsonResponse
    {
        $this->assertTenantOwnership($testCase);

        $reason = $request->input('reason');

        // Marked removed, not deleted. A generated test somebody rejected is
        // feedback about the generator, and deleting the row throws that away.
        return $this->success($this->testCases->remove($testCase, $this->sireUser(), $reason));
    }

    /**
     * POST /sire/test-cases/{testCase}/result
     *
     * The only way a result is ever written. One person, one test, one result —
     * no bulk pass, no inferred pass, no automatic pass.
     */
    public function result(Request $request, IssueTestCase $testCase): JsonResponse
    {
        $this->assertTenantOwnership($testCase);

        $data = $request->validate([
            'result' => ['required', Rule::in(IssueTestCase::RESULTS)],
            'note'   => ['nullable', 'string', 'max:5000'],
        ]);

        return $this->success($this->testCases->record($testCase, $data['result'], $this->sireUser(), $data['note'] ?? null));
    }

    /** Clear a result so the test can be re-run after a fix. Audited. */
    public function resetResult(Request $request, IssueTestCase $testCase): JsonResponse
    {
        $this->assertTenantOwnership($testCase);

        return $this->success($this->testCases->reset($testCase, $this->sireUser()));
    }
}
