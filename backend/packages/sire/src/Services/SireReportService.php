<?php

namespace Sire\Services;

use Sire\Models\Report;
use Sire\Dto\SireUserIdentity;
use Sire\Contracts\SireNumberingProvider;
use Sire\Support\SireStatus;
use Sire\Support\SireTrack;
use Sire\Support\SireWorkflow;
use Illuminate\Support\Facades\DB;

/**
 * SIRE — creating and reading an issue.
 *
 * CORE layer. Every read chains ->forTenant($tenantId): scoping in this codebase
 * is opt-in with no global scope and no safety net, so a missing chain returns
 * every tenant's rows with no error and no log line.
 *
 * This service does NOT move an issue through the workflow. `status` is written
 * only by SireWorkflowService — one machine, one writer.
 */
class SireReportService
{
    public function __construct(
        private readonly SireAccessService $access,
        private readonly SireSlaService $sla,
        private readonly SireNumberingProvider $numbers,
    ) {
    }

    /**
     * Create an issue.
     *
     * tenant_id and reporter_id come from the authenticated user, never from the
     * payload — StoreReportRequest strips them before validation and there is no
     * path here that would accept them.
     */
    public function create(int $tenantId, SireUserIdentity $actor, array $data): Report
    {
        return DB::transaction(function () use ($tenantId, $actor, $data) {
            $report = Report::create([
                'tenant_id'      => $tenantId,
                'report_number'  => $this->numbers->next($tenantId, 'sire_report'),
                'status'         => SireWorkflow::INITIAL,
                'workflow_track' => $data['workflow_track'] ?? SireTrack::DEFECT,

                'title'       => $data['title'],
                'description' => $data['description'],
                'category_id' => $data['category_id'] ?? null,
                'severity_id' => $data['severity_id'] ?? null,
                'priority'    => $data['priority'] ?? null,

                'steps_to_reproduce' => $data['steps_to_reproduce'] ?? null,
                'expected_result'    => $data['expected_result'] ?? null,
                'actual_result'      => $data['actual_result'] ?? null,

                'occurred_at' => $data['occurred_at'] ?? now(),
                'origin'      => $data['origin'] ?? 'internal',
                'reporter_id' => $actor->id,

                // The SLA clock starts at creation, not at triage. Time an issue
                // spends waiting to be looked at is the part worth measuring.
                'sla_started_at' => now(),
            ]);

            $report->recordAudit('Issue reported', $actor, null, [
                'action' => 'created', 'system' => true, 'origin' => $report->origin,
            ]);

            return $report;
        });
    }

    /**
     * One issue, with everything the detail view needs in a single response.
     *
     * `available_transitions` comes from the workflow service so the client renders
     * what the server allows rather than recomputing the rules — the state machine
     * exists in one place.
     */
    public function detail(Report $report, ?SireUserIdentity $viewer = null): array
    {
        $report->loadMissing([
            'category:id,code,name,release_class',
            'severity:id,code,name,level',
            'assignee:id,name', 'qaAssignee:id,name', 'reporter:id,name',
            'duplicateOf:id,report_number,title,status',
            'recurrenceGroup:id,reference,title,occurrence_count,recurrence_risk',
            'rootCause', 'context',
        ]);

        return [
            'report' => $report,
            'sla'    => $this->sla->for($report),
            // Resolved through the provider rather than joined: the customer may
            // live in another database, or behind an API, in a host that
            // implements SireCustomerProvider its own way. One extra lookup on a
            // detail page is the price of not assuming a `clients` table exists.
            'customer' => $this->customer($report),
            // Everyone working it besides the owner. Resolved through the user
            // seam so a host with an external directory answers this too.
            'co_assignees' => $this->coAssignees($report),
            // Asked once, here, rather than inferred by the client from which
            // transitions happen to be legal right now. Whether somebody may say
            // whose problem this is does not depend on what state it is in.
            'can_set_customer' => $viewer !== null
                && $this->access->can($viewer, 'sire.report.triage', $report),
            'available_transitions' => $viewer
                ? app(SireWorkflowService::class)->availableFor($report, $viewer)
                : [],
        ];
    }

    /**
     * The people working this issue alongside its owner.
     *
     * @return array<int, array<string, mixed>>
     */
    private function coAssignees(Report $report): array
    {
        $ids = \Sire\Models\ReportAssignee::query()
            ->forTenant($report->tenant_id)
            ->where('report_id', $report->id)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($ids === []) {
            return [];
        }

        return array_values(array_map(
            fn ($identity) => [
                'id'           => $identity->id,
                'display_name' => $identity->displayName,
                'role'         => $identity->role,
            ],
            app(\Sire\Contracts\SireUserProvider::class)->lookupMany((int) $report->tenant_id, $ids),
        ));
    }

    /**
     * The affected customer, or null.
     *
     * Null covers three different things deliberately: no customer was ever set,
     * no directory is connected, or the customer has since been removed. None of
     * them is an error, and an issue page must render in all three.
     *
     * @return array<string, mixed>|null
     */
    private function customer(Report $report): ?array
    {
        if ($report->customer_id === null) {
            return null;
        }

        $customers = app(\Sire\Contracts\SireCustomerProvider::class);

        if (! $customers->isAvailable()) {
            return null;
        }

        return $customers->find((int) $report->tenant_id, $report->customer_id)?->toArray();
    }

    /** The register list. Filters are applied by the dashboard service. */
    public function paginate(int $tenantId, SireUserIdentity $viewer, array $filters = [], int $perPage = 25)
    {
        return $this->access
            ->scopeVisible(Report::query()->forTenant($tenantId), $viewer)
            ->with(['category:id,name', 'severity:id,name,level', 'assignee:id,name'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->whereIn('status', (array) $v))
            ->when($filters['module'] ?? null, fn ($q, $v) => $q->where('module', $v))
            ->when(
                $filters['open_only'] ?? false,
                fn ($q) => $q->whereNotIn('status', SireStatus::TERMINAL),
            )
            ->latest('created_at')
            ->paginate($perPage);
    }
}
