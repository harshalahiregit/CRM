<?php

namespace Sire\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Sire\Exceptions\SireException;
use Sire\Http\Controllers\Concerns\ResolvesSireUser;
use Sire\Http\Controllers\Concerns\SireApiResponse;
use Sire\Http\Requests\DashboardFilterRequest;
use Sire\Models\Report;
use Sire\Services\SireAccessService;
use Sire\Services\SireExportService;
use Sire\Services\SireWorkflowService;

/**
 * SIRE — take the backlog out in one piece, send the fixes back in one call.
 *
 * FOR DEVELOPERS. Both routes exist to remove the round trip, which is the real
 * cost of a large backlog: forty issues is eighty page loads, and the fixing was
 * never the slow part.
 *
 * The export enforces `sire.export`, a capability that has been in the
 * vocabulary since the beginning and was never once checked -- it described an
 * endpoint nobody had built.
 */
class SireExportController
{
    use ResolvesSireUser;
    use SireApiResponse;

    /** One transaction per issue, so a bad one cannot roll back the good ones. */
    private const MAX_TRANSITIONS = 100;

    public function __construct(
        private readonly SireExportService $export,
        private readonly SireWorkflowService $workflow,
        private readonly SireAccessService $access,
    ) {
    }

    /**
     * GET /sire/export — the whole filtered backlog as one markdown brief.
     *
     * Takes the register's own filters, so what you export is what you were
     * looking at. Served as text/markdown rather than a download by default: it
     * is usually going to be pasted somewhere, and a file in the Downloads
     * folder is one more step before that happens. `?download=1` when a file is
     * genuinely wanted.
     */
    public function markdown(DashboardFilterRequest $request): Response
    {
        $user = $this->sireUser();

        // Exporting is reading a lot at once, which is a different act from
        // reading one issue -- it is the form the backlog leaves the building in.
        $this->access->assert($user, 'sire.export');

        $body = $this->export->markdown(
            (int) $user->tenantId,
            $user,
            (string) ($request->validated('scope') ?? SireExportService::defaultScope()),
            $request->filters(),
            (int) ($request->validated('limit') ?? 200),
            // Pictures by default. A screenshot is the most useful thing on a bug
            // report, and `images=0` exists for the reader who wants a small file
            // to skim rather than a document to work from.
            ! $request->has('images') || $request->boolean('images'),
            // Absolute, so the "full size" links still resolve once the brief has
            // been pasted somewhere that is not the app.
            rtrim((string) config('app.frontend_url', config('app.url')), '/'),
        );

        $headers = ['Content-Type' => 'text/markdown; charset=utf-8'];

        if ($request->boolean('download')) {
            $headers['Content-Disposition'] = 'attachment; filename="sire-issues-'
                .now()->format('Y-m-d').'.md"';
        }

        return response($body, 200, $headers);
    }

    /**
     * POST /sire/reports/transitions — move many issues in one request.
     *
     * NOT A WAY AROUND THE WORKFLOW. Every entry runs the same capability check,
     * the same guard and the same required-field rules as the single-issue
     * endpoint, and each is audited on its own. The only thing saved is the page
     * load.
     *
     * Each issue gets its OWN transaction and its own result. One failure --
     * a missing fix summary, an issue somebody else already moved -- must not
     * roll back nineteen good ones and leave the caller guessing which. The
     * response says what happened to each, and the caller retries the failures.
     */
    public function transitions(Request $request): JsonResponse
    {
        $user = $this->sireUser();

        $data = $request->validate([
            'transitions'               => ['required', 'array', 'min:1', 'max:'.self::MAX_TRANSITIONS],
            'transitions.*.report_id'   => ['required', 'integer'],
            'transitions.*.action'      => ['required', 'string', 'max:64'],
            'transitions.*.fix_summary' => ['nullable', 'string', 'max:20000'],
            'transitions.*.qa_notes'    => ['nullable', 'string', 'max:20000'],
            'transitions.*.release_ref' => ['nullable', 'string', 'max:255'],

            // Without this, `close_directly` is unusable from here: it requires a
            // resolution note, and a field the validator drops can never satisfy
            // a requirement. The same applies to reject / wont_fix /
            // cannot_reproduce, which were equally unreachable in bulk.
            'transitions.*.resolution_note' => ['nullable', 'string', 'max:20000'],

            // "Assign these nine to me" is the other bulk action people actually
            // want, and it is the same transition with a payload field.
            'transitions.*.assignee_id' => ['nullable', 'integer'],
            'transitions.*.severity_id' => ['nullable', 'integer'],
            'transitions.*.priority'    => ['nullable', 'string', 'max:8'],
        ]);

        $results = [];

        foreach ($data['transitions'] as $entry) {
            $results[] = $this->applyOne($entry, (int) $user->tenantId, $user);
        }

        $failed = count(array_filter($results, fn (array $r) => ! $r['ok']));

        return $this->success([
            'applied' => count($results) - $failed,
            'failed'  => $failed,
            'results' => $results,
        ]);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function applyOne(array $entry, int $tenantId, $user): array
    {
        $id = (int) $entry['report_id'];

        // forTenant, not find(): another tenant's id must come back as "not
        // found", exactly as it does on the single-issue route. A bulk endpoint
        // is not a licence to skip the scope.
        $report = Report::query()->forTenant($tenantId)->find($id);

        if ($report === null) {
            return ['report_id' => $id, 'ok' => false, 'error' => 'Not found in your workspace.'];
        }

        try {
            $fresh = DB::transaction(fn () => $this->workflow->apply(
                $report,
                (string) $entry['action'],
                $user,
                array_filter([
                    'fix_summary'     => $entry['fix_summary'] ?? null,
                    'qa_notes'        => $entry['qa_notes'] ?? null,
                    'release_ref'     => $entry['release_ref'] ?? null,
                    'resolution_note' => $entry['resolution_note'] ?? null,
                    'assignee_id'     => $entry['assignee_id'] ?? null,
                    'severity_id'     => $entry['severity_id'] ?? null,
                    'priority'        => $entry['priority'] ?? null,
                ], static fn ($v) => $v !== null),
            ));

            return [
                'report_id'     => $id,
                'report_number' => $fresh->report_number,
                'ok'            => true,
                'status'        => $fresh->status,
            ];
        } catch (SireException $e) {
            // A rule violation is an answer, not a crash: this issue was not in a
            // state the action allows, and the caller needs to know which.
            return ['report_id' => $id, 'ok' => false, 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            report($e);

            return ['report_id' => $id, 'ok' => false, 'error' => 'That issue could not be moved.'];
        }
    }
}
