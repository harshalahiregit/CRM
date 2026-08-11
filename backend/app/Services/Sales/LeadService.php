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
        $junk      = (clone $leads)->junk()->count();
        $pipeline  = (clone $leads)->active()->sum('lead_value');

        $conversionRate = $total > 0 ? round(($converted / $total) * 100, 1) : 0;

        $thisMonth      = (clone $leads)->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->count();
        $thisMonthValue = (clone $leads)->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->sum('lead_value');

        // One grouped query instead of two per status — this ran 2N queries and is
        // read on every Leads page load. `id` is included so the page can match a
        // count to its filter chip; without it the chips could only match on name.
        $statusAgg = Lead::forTenant($tenantId)->active()
            ->selectRaw('status_id, COUNT(*) as c, COALESCE(SUM(lead_value), 0) as v')
            ->groupBy('status_id')
            ->get()
            ->keyBy('status_id');

        $byStatus = LeadStatus::forTenant($tenantId)->ordered()->get()->map(fn ($s) => [
            'id'    => $s->id,
            'name'  => $s->name,
            'color' => $s->color,
            'count' => (int) ($statusAgg[$s->id]->c ?? 0),
            'value' => (float) ($statusAgg[$s->id]->v ?? 0),
        ]);

        // Leads with no status still exist (created before any status was defined,
        // or cleared since) and belong to no chip — surfaced so the chip counts add
        // up to the total instead of silently falling short.
        $unassigned = (clone $leads)->active()->whereNull('status_id')->count();

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
            'junk'             => $junk,
            'unassigned'       => $unassigned,
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

    /**
     * Mark lost, keeping WHY.
     *
     * The old CRM stored only the boolean, so "14 lost leads" could never be
     * reviewed afterwards. The reason is optional (never block the action) but it
     * is what makes the number useful later.
     */
    public function markLost(Lead $lead, int $tenantId, int $userId, ?string $reason = null): Lead
    {
        $this->assertTenant($lead, $tenantId);
        $lead->markAsLost($userId);

        $reason = trim((string) $reason);
        $lead->update([
            'lost_reason' => $reason !== '' ? mb_substr($reason, 0, 255) : null,
            'lost_at'     => now(),
        ]);
        if ($reason !== '') {
            $lead->logActivity('lost_reason', "Lost reason: {$reason}", null, null, $userId);
        }

        Log::channel('sales')->info('Lead marked lost', ['lead_id' => $lead->id, 'tenant_id' => $tenantId]);
        return $lead->fresh()->load(['status', 'source']);
    }

    public function markJunk(Lead $lead, int $tenantId, int $userId, ?string $reason = null): Lead
    {
        $this->assertTenant($lead, $tenantId);
        $lead->markAsJunk($userId);

        $reason = trim((string) $reason);
        $lead->update([
            'junk_reason' => $reason !== '' ? mb_substr($reason, 0, 255) : null,
            'junk_at'     => now(),
        ]);
        if ($reason !== '') {
            $lead->logActivity('junk_reason', "Junk reason: {$reason}", null, null, $userId);
        }

        Log::channel('sales')->info('Lead marked junk', ['lead_id' => $lead->id, 'tenant_id' => $tenantId]);
        return $lead->fresh()->load(['status', 'source']);
    }

    public function restore(Lead $lead, int $tenantId, int $userId): Lead
    {
        $this->assertTenant($lead, $tenantId);
        $lead->restoreFromLostJunk($userId);
        // Clear the audit too — a restored lead is active again, and a stale
        // "lost because price" on a live lead would read as current.
        $lead->update(['lost_reason' => null, 'lost_at' => null, 'junk_reason' => null, 'junk_at' => null]);
        Log::channel('sales')->info('Lead restored', ['lead_id' => $lead->id, 'tenant_id' => $tenantId]);
        return $lead->fresh()->load(['status', 'source']);
    }

    /**
     * Build the customer record a converted lead becomes.
     *
     * Mirrors the old CRM's convert-to-customer: the lead's address becomes the
     * billing address, a primary contact is created from the lead's own name and
     * email, and the lead's custom-field values are carried over when asked for.
     * Anything the caller supplies in $data wins, so the convert dialog can correct
     * details on the way through.
     */
    private function createClientFromLead(Lead $lead, array $data, int $tenantId): \App\Models\Customer\Client
    {
        $company = trim((string) ($data['company'] ?? $lead->company ?? $lead->name));

        $client = \App\Models\Customer\Client::create([
            'tenant_id' => $tenantId,
            'lead_id'   => $lead->id,
            'company'   => $company !== '' ? $company : 'Unnamed customer',
            'phone'     => $data['phone']   ?? $lead->phone,
            'website'   => $data['website'] ?? $lead->website,
            'address'   => $data['address'] ?? $lead->address,
            'city'      => $data['city']    ?? $lead->city,
            'state'     => $data['state']   ?? $lead->state,
            'zip'       => $data['zip']     ?? $lead->zip,
            'country'   => $data['country'] ?? $lead->country,
            // The old CRM copied address → billing_street on convert; keep that so
            // the first invoice has somewhere to bill.
            'billing_street'  => $data['address'] ?? $lead->address,
            'billing_city'    => $data['city']    ?? $lead->city,
            'billing_state'   => $data['state']   ?? $lead->state,
            'billing_zip'     => $data['zip']     ?? $lead->zip,
            'billing_country' => $data['country'] ?? $lead->country,
            'active'    => true,
            'added_by'  => $lead->created_by,
        ]);

        // A customer with no contact can't be emailed or given portal access, so
        // the lead's own details become the primary contact. Split "First Last"
        // on the first space only — surnames can contain spaces.
        $name  = trim((string) ($data['contact_name'] ?? $lead->name));
        $parts = preg_split('/\s+/', $name, 2) ?: [];
        $email = trim((string) ($data['contact_email'] ?? $lead->email ?? ''));

        if ($name !== '' || $email !== '') {
            $client->contacts()->create([
                'tenant_id'  => $tenantId,
                'first_name' => $parts[0] ?? $name,
                'last_name'  => $parts[1] ?? null,
                'email'      => $email !== '' ? $email : null,
                'phone'      => $data['phone'] ?? $lead->phone,
                'title'      => $lead->title,
                'is_primary' => true,
                'active'     => true,
            ]);
        }

        // Notes and custom fields are opt-in, matching the old CRM's checkboxes.
        if (! empty($data['transfer_notes'])) {
            $this->transferNotes($lead, $client, $tenantId);
        }

        if (! empty($data['transfer_custom_fields'])) {
            $this->transferCustomFields($lead, $client, $tenantId);
        }

        return $client;
    }

    /** Copy the lead's notes onto the new customer so the history isn't orphaned. */
    private function transferNotes(Lead $lead, \App\Models\Customer\Client $client, int $tenantId): void
    {
        foreach ($lead->notes()->get() as $note) {
            \App\Models\Customer\ClientNote::create([
                'tenant_id'  => $tenantId,
                'client_id'  => $client->id,
                'content'    => $note->content,
                'created_by' => $note->created_by,
            ]);
        }
    }

    /**
     * Carry lead custom-field values across to the customer.
     *
     * Matched by field NAME, since lead fields and customer fields are separate
     * definitions — a lead "Industry" and a customer "Industry" are different rows
     * with different ids. Values with no matching customer field are skipped rather
     * than invented.
     */
    private function transferCustomFields(Lead $lead, \App\Models\Customer\Client $client, int $tenantId): void
    {
        $leadValues = \App\Models\Customer\CustomFieldValue::forTenant($tenantId)
            ->where('field_to', 'leads')->where('rel_id', $lead->id)
            ->with('field:id,name')
            ->get();

        if ($leadValues->isEmpty()) {
            return;
        }

        $customerFields = \App\Models\Customer\CustomField::forTenant($tenantId)
            ->where('field_to', 'customers')->get()
            ->keyBy(fn ($f) => mb_strtolower($f->name));

        foreach ($leadValues as $value) {
            $target = $customerFields[mb_strtolower((string) $value->field?->name)] ?? null;
            if (! $target) {
                continue;
            }

            \App\Models\Customer\CustomFieldValue::updateOrCreate(
                ['field_id' => $target->id, 'rel_id' => $client->id],
                ['tenant_id' => $tenantId, 'field_to' => 'customers', 'value' => $value->value],
            );
        }
    }

    public function convert(Lead $lead, array $data, int $tenantId, int $userId): array
    {
        $this->assertTenant($lead, $tenantId);

        if ($lead->date_converted) {
            throw new BusinessException('Lead already converted', 422);
        }

        return DB::transaction(function () use ($lead, $data, $tenantId) {
            $wonStatus = LeadStatus::getWonStatus($tenantId);

            // Actually create the customer. This previously only flipped a flag, so
            // "Convert to customer" produced no customer at all — the lead was
            // marked converted and clients.lead_id (which exists for exactly this)
            // stayed empty.
            $client = $this->createClientFromLead($lead, $data, $tenantId);

            $lead->update([
                'status_id'      => $wonStatus?->id ?? $lead->status_id,
                'date_converted' => now(),
                'client_id'      => $client->id,
                'lost'           => false,
                'junk'           => false,
                'lost_reason'    => null,
                'junk_reason'    => null,
            ]);

            // Re-point the lead's proposals at the new customer so history follows
            // it; rel_id must move too, or they'd point at a lead id as a customer.
            $lead->proposals()->update(['rel_type' => 'customer', 'rel_id' => $client->id]);

            $this->updateGoalAchievement($lead, $tenantId);

            $lead->logActivity('converted', 'Lead converted to customer', null, $data['company'] ?? $lead->company);

            // Generate any won-deal commission entries (idempotent).
            $this->commissionService->computeForWonLead($lead->fresh());

            Log::channel('sales')->info('Lead converted', ['lead_id' => $lead->id, 'tenant_id' => $tenantId]);

            return [
                'message' => 'Lead converted successfully',
                'lead'    => $lead->fresh()->load(['status', 'source']),
                'client'  => $client->fresh()->load('contacts'),
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
