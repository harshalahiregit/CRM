<?php

namespace App\Services\Sales;

use App\Exceptions\BusinessException;
use App\Exceptions\UnauthorizedTenantException;
use App\Models\Sales\Lead;
use App\Models\Sales\LeadGoal;
use App\Models\Sales\LeadNote;
use App\Models\Sales\LeadQuestionnaireResponse;
use App\Models\Sales\LeadStatus;
use App\Repositories\Sales\LeadRepository;
use App\Support\HtmlSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LeadService
{
    public function __construct(
        private LeadRepository $leadRepository,
        private CommissionService $commissionService,
    )
    {
    }

    public function list(int $tenantId, array $filters)
    {
        return $this->leadRepository->filtered($tenantId, $filters);
    }

    public function kanban(int $tenantId)
    {
        // The board IS the statuses — with none configured it has no columns and
        // reads as a broken page. The Leads screen loads statuses and the board in
        // parallel, so this can't rely on the statuses call having seeded first.
        app(LeadSettingService::class)->ensureDefaults($tenantId);

        return $this->leadRepository->kanbanColumns($tenantId);
    }

    public function summary(int $tenantId): array
    {
        $leads = Lead::forTenant($tenantId);

        $total     = (clone $leads)->count();
        $active    = (clone $leads)->active()->count();
        $hot       = (clone $leads)->active()->where('lead_temperature', 'Hot')->count();
        $warm      = (clone $leads)->active()->where('lead_temperature', 'Warm')->count();
        $cold      = (clone $leads)->active()->where('lead_temperature', 'Cold')->count();
        $converted = (clone $leads)->converted()->count();
        $lost      = (clone $leads)->lost()->count();
        $pipeline  = (clone $leads)->active()->sum('lead_value');

        $conversionRate = $total > 0 ? round(($converted / $total) * 100, 1) : 0;

        $thisMonth      = (clone $leads)->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->count();
        $thisMonthValue = (clone $leads)->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->sum('lead_value');

        $byStatus = LeadStatus::forTenant($tenantId)->ordered()->get()->map(function ($s) use ($tenantId) {
            $count = Lead::forTenant($tenantId)->active()->where('status_id', $s->id)->count();
            $value = Lead::forTenant($tenantId)->active()->where('status_id', $s->id)->sum('lead_value');
            return ['name' => $s->name, 'color' => $s->color, 'count' => $count, 'value' => $value];
        });

        $bySource = Lead::forTenant($tenantId)->active()
            ->selectRaw('source_id, count(*) as count')
            ->groupBy('source_id')
            ->with('source:id,name')
            ->get()
            ->map(fn($r) => ['name' => $r->source->name ?? 'Unknown', 'count' => $r->count]);

        return [
            'total'            => $total,
            'active'           => $active,
            'hot'              => $hot,
            'warm'             => $warm,
            'cold'             => $cold,
            'converted'        => $converted,
            'lost'             => $lost,
            'pipeline_value'   => $pipeline,
            'conversion_rate'  => $conversionRate,
            'this_month'       => $thisMonth,
            'this_month_value' => $thisMonthValue,
            'by_status'        => $byStatus,
            'by_source'        => $bySource,
        ];
    }

    /**
     * Turn a typed-in source NAME into a `source_id`.
     *
     * The lead form asks for the source as free text rather than a dropdown, but
     * `leads.source_id` is a foreign key — so the name is matched against the
     * tenant's existing sources and only created when it's genuinely new. Matching
     * is case-insensitive so "google" doesn't become a second "Google".
     *
     * `source` is not a column (it's the relation name), so it is always removed
     * from the payload before the model sees it.
     */
    private function resolveSource(array &$data, int $tenantId): void
    {
        if (! array_key_exists('source', $data)) {
            return;
        }

        $name = trim((string) $data['source']);
        unset($data['source']);

        if ($name === '') {
            // Cleared on purpose — drop the association rather than keeping the old
            // one, but only when the caller actually sent the field.
            $data['source_id'] = null;

            return;
        }

        $existing = \App\Models\Sales\LeadSource::forTenant($tenantId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        $data['source_id'] = $existing
            ? $existing->id
            : \App\Models\Sales\LeadSource::create(['tenant_id' => $tenantId, 'name' => $name])->id;
    }

    public function create(array $data, int $tenantId, int $userId): Lead
    {
        $this->resolveSource($data, $tenantId);
        // Rich text (notepad editor) — sanitized before it ever reaches the DB.
        $data = HtmlSanitizer::cleanFields($data, ['description']);

        $lead = Lead::create([
            ...$data,
            'tenant_id'  => $tenantId,
            'created_by' => $userId,
            'hash'       => Str::random(32),
        ]);

        if (!empty($data['assigned_to'])) {
            $lead->update(['date_assigned' => now()]);
        }

        $lead->logActivity('created', "Lead \"{$lead->name}\" created");
        Log::channel('sales')->info('Lead created', ['lead_id' => $lead->id, 'tenant_id' => $tenantId]);

        return $lead->load(['status', 'source', 'assignedUser:id,name']);
    }

    public function show(Lead $lead, int $tenantId): Lead
    {
        $this->assertTenant($lead, $tenantId);

        return $lead->load([
            'status', 'source',
            'assignedUser:id,name',
            'creator:id,name',
            'notes.creator:id,name',
            'activities.performer:id,name',
            'proposals',
            'questionnaireResponses.questionnaire',
        ]);
    }

    public function update(Lead $lead, array $data, int $tenantId): Lead
    {
        $this->assertTenant($lead, $tenantId);

        $this->resolveSource($data, $tenantId);
        $data = HtmlSanitizer::cleanFields($data, ['description']);

        $lead->update($data);
        $lead->logActivity('updated', "Lead \"{$lead->name}\" updated");
        Log::channel('sales')->info('Lead updated', ['lead_id' => $lead->id, 'tenant_id' => $tenantId]);

        return $lead->fresh()->load(['status', 'source', 'assignedUser:id,name']);
    }

    public function delete(Lead $lead, int $tenantId): void
    {
        $this->assertTenant($lead, $tenantId);
        $lead->delete();
        Log::channel('sales')->info('Lead deleted', ['lead_id' => $lead->id, 'tenant_id' => $tenantId]);
    }

    public function updateStatus(Lead $lead, int $statusId, int $tenantId): Lead
    {
        $this->assertTenant($lead, $tenantId);

        $oldStatus = $lead->status->name ?? 'None';
        $lead->update(['status_id' => $statusId]);
        $newStatus = $lead->fresh()->status->name ?? 'None';

        $lead->logActivity('status_changed', "Status changed from {$oldStatus} to {$newStatus}", $oldStatus, $newStatus);
        Log::channel('sales')->info('Lead status changed', ['lead_id' => $lead->id, 'tenant_id' => $tenantId]);

        return $lead->fresh()->load(['status', 'source', 'assignedUser:id,name']);
    }

    public function assign(Lead $lead, int $assignedTo, int $tenantId): Lead
    {
        $this->assertTenant($lead, $tenantId);

        $lead->update(['assigned_to' => $assignedTo, 'date_assigned' => now()]);

        $assignee = $lead->fresh()->assignedUser;
        $lead->logActivity('assigned', "Lead assigned to {$assignee->name}", null, $assignee->name);
        Log::channel('sales')->info('Lead assigned', ['lead_id' => $lead->id, 'assigned_to' => $assignedTo, 'tenant_id' => $tenantId]);

        return $lead->fresh()->load(['status', 'source', 'assignedUser:id,name']);
    }

    public function markLost(Lead $lead, int $tenantId, int $userId): Lead
    {
        $this->assertTenant($lead, $tenantId);
        $lead->markAsLost($userId);
        Log::channel('sales')->info('Lead marked lost', ['lead_id' => $lead->id, 'tenant_id' => $tenantId]);
        return $lead->fresh()->load(['status', 'source']);
    }

    public function markJunk(Lead $lead, int $tenantId, int $userId): Lead
    {
        $this->assertTenant($lead, $tenantId);
        $lead->markAsJunk($userId);
        Log::channel('sales')->info('Lead marked junk', ['lead_id' => $lead->id, 'tenant_id' => $tenantId]);
        return $lead->fresh()->load(['status', 'source']);
    }

    public function restore(Lead $lead, int $tenantId, int $userId): Lead
    {
        $this->assertTenant($lead, $tenantId);
        $lead->restoreFromLostJunk($userId);
        Log::channel('sales')->info('Lead restored', ['lead_id' => $lead->id, 'tenant_id' => $tenantId]);
        return $lead->fresh()->load(['status', 'source']);
    }

    public function convert(Lead $lead, array $data, int $tenantId, int $userId): array
    {
        $this->assertTenant($lead, $tenantId);

        if ($lead->date_converted) {
            throw new BusinessException('Lead already converted', 422);
        }

        return DB::transaction(function () use ($lead, $data, $tenantId) {
            $wonStatus = LeadStatus::getWonStatus($tenantId);

            $lead->update([
                'status_id'      => $wonStatus?->id ?? $lead->status_id,
                'date_converted' => now(),
                'lost'           => false,
                'junk'           => false,
            ]);

            $lead->proposals()->update(['rel_type' => 'customer']);

            $this->updateGoalAchievement($lead, $tenantId);

            $lead->logActivity('converted', 'Lead converted to customer', null, $data['company'] ?? $lead->company);

            // Generate any won-deal commission entries (idempotent).
            $this->commissionService->computeForWonLead($lead->fresh());

            Log::channel('sales')->info('Lead converted', ['lead_id' => $lead->id, 'tenant_id' => $tenantId]);

            return [
                'message' => 'Lead converted successfully',
                'lead'    => $lead->fresh()->load(['status', 'source']),
            ];
        });
    }

    public function addNote(Lead $lead, array $data, int $tenantId, int $userId): LeadNote
    {
        $this->assertTenant($lead, $tenantId);

        $note = LeadNote::create([
            'tenant_id'    => $tenantId,
            'lead_id'      => $lead->id,
            'content'      => $data['content'],
            'contact_date' => $data['contact_date'] ?? null,
            'created_by'   => $userId,
        ]);

        if (!empty($data['contact_date'])) {
            $lead->update(['last_contact_date' => $data['contact_date']]);
        }

        $lead->logActivity('note_added', 'Note added to lead');
        Log::channel('sales')->info('Lead note added', ['lead_id' => $lead->id, 'note_id' => $note->id, 'tenant_id' => $tenantId]);

        return $note->load('creator:id,name');
    }

    public function submitQuestionnaireResponse(Lead $lead, array $data, int $tenantId): LeadQuestionnaireResponse
    {
        $this->assertTenant($lead, $tenantId);

        $response = LeadQuestionnaireResponse::updateOrCreate(
            [
                'tenant_id'        => $tenantId,
                'questionnaire_id' => $data['questionnaire_id'],
                'lead_id'          => $lead->id,
            ],
            [
                'answers'      => $data['answers'],
                'submitted_at' => now(),
            ]
        );

        $lead->logActivity('questionnaire_submitted', 'Questionnaire response submitted');
        Log::channel('sales')->info('Lead questionnaire response submitted', ['lead_id' => $lead->id, 'tenant_id' => $tenantId]);

        return $response;
    }

    public function bulkAction(array $data, int $tenantId, int $userId): int
    {
        $leads = Lead::forTenant($tenantId)->whereIn('id', $data['lead_ids'])->get();
        $count = 0;

        foreach ($leads as $lead) {
            switch ($data['action']) {
                case 'delete':
                    $lead->delete();
                    break;
                case 'status':
                    $lead->update(['status_id' => $data['value']]);
                    $lead->logActivity('status_changed', 'Status changed via bulk action');
                    break;
                case 'assign':
                    $lead->update(['assigned_to' => $data['value'], 'date_assigned' => now()]);
                    $lead->logActivity('assigned', 'Assigned via bulk action');
                    break;
                case 'lost':
                    $lead->markAsLost($userId);
                    break;
                case 'junk':
                    $lead->markAsJunk($userId);
                    break;
                case 'source':
                    $lead->update(['source_id' => $data['value']]);
                    break;
            }
            $count++;
        }

        Log::channel('sales')->info('Lead bulk action applied', [
            'action' => $data['action'], 'count' => $count, 'tenant_id' => $tenantId,
        ]);

        return $count;
    }

    private function assertTenant(Lead $lead, int $tenantId): void
    {
        if ($lead->tenant_id !== $tenantId) {
            Log::channel('sales')->warning('Unauthorized lead access attempt', ['lead_id' => $lead->id, 'tenant_id' => $tenantId]);
            throw new UnauthorizedTenantException('Unauthorized');
        }
    }

    private function updateGoalAchievement(Lead $lead, int $tenantId): void
    {
        $goals = LeadGoal::forTenant($tenantId)
            ->active()
            ->where(function ($q) use ($lead) {
                $q->whereNull('user_id')
                  ->orWhere('user_id', $lead->assigned_to)
                  ->orWhere('user_id', $lead->created_by);
            })
            ->get();

        foreach ($goals as $goal) {
            $goal->increment('achieved_count');
            $goal->increment('achieved_value', $lead->lead_value);
        }
    }
}
