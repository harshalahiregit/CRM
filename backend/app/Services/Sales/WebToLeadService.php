<?php

namespace App\Services\Sales;

use App\Exceptions\ResourceNotFoundException;
use App\Exceptions\UnauthorizedTenantException;
use App\Models\Sales\Lead;
use App\Models\Sales\WebToLeadForm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WebToLeadService
{
    /** Lead columns a public form is allowed to populate. */
    private const ALLOWED_LEAD_FIELDS = [
        'name', 'title', 'company', 'email', 'phone', 'website',
        'city', 'state', 'country', 'zip', 'address', 'description', 'industry',
    ];

    /* ── Authenticated CRUD ─────────────────────────────────────── */
    public function list(int $tenantId)
    {
        return WebToLeadForm::forTenant($tenantId)->with(['source:id,name', 'status:id,name'])->latest()->get();
    }

    public function create(array $data, int $tenantId, int $userId): WebToLeadForm
    {
        return WebToLeadForm::create([...$data, 'tenant_id' => $tenantId, 'created_by' => $userId]);
    }

    public function show(WebToLeadForm $form, int $tenantId): WebToLeadForm
    {
        $this->assertTenant($form, $tenantId);
        return $form->load(['source:id,name', 'status:id,name', 'assignee:id,name']);
    }

    public function update(WebToLeadForm $form, array $data, int $tenantId): WebToLeadForm
    {
        $this->assertTenant($form, $tenantId);
        $form->update($data);
        return $form->fresh();
    }

    public function delete(WebToLeadForm $form, int $tenantId): void
    {
        $this->assertTenant($form, $tenantId);
        $form->delete();
    }

    /* ── Public (unauthenticated) ───────────────────────────────── */

    /**
     * Resolve a form by its public key. This is the ONE intentionally
     * un-tenant-scoped query — the tenant is DERIVED from the form.
     */
    public function resolvePublicForm(string $formKey): WebToLeadForm
    {
        $form = WebToLeadForm::where('form_key', $formKey)->where('is_active', true)->first();
        if (! $form) {
            throw new ResourceNotFoundException('Form');
        }
        return $form;
    }

    /**
     * Public payload for rendering the form — never exposes internal ids
     * beyond what's needed, and no secrets.
     */
    public function publicPayload(WebToLeadForm $form): array
    {
        return [
            'form_key'        => $form->form_key,
            'name'            => $form->name,
            'fields'          => $form->form_data ?: [['key' => 'name', 'label' => 'Full Name', 'type' => 'text', 'required' => true]],
            'success_message' => $form->success_message ?: 'Thank you! We will get back to you shortly.',
            'redirect_url'    => $form->redirect_url,
        ];
    }

    /**
     * Create a Lead from a public submission. tenant_id is taken from the
     * resolved form — NEVER from request input. No auth here, so the
     * BelongsToTenant auto-fill won't fire; everything is set explicitly.
     */
    public function submit(WebToLeadForm $form, array $payload, array $extras = []): Lead
    {
        // Keep only known lead columns — never mass-assign arbitrary posted keys.
        $leadData = array_intersect_key($payload, array_flip(self::ALLOWED_LEAD_FIELDS));

        // Append answers to custom (non-standard) fields into the description so
        // they are captured rather than dropped.
        if (! empty($extras)) {
            $lines = collect($extras)->map(fn ($v, $k) => "{$k}: {$v}")->implode("\n");
            $leadData['description'] = trim(($leadData['description'] ?? '') . "\n" . $lines);
        }

        return DB::transaction(function () use ($form, $leadData) {
            // Dedup by email within the form's tenant unless duplicates allowed.
            if (! $form->allow_duplicate && ! empty($leadData['email'])) {
                $existing = Lead::forTenant($form->tenant_id)->where('email', $leadData['email'])->first();
                if ($existing) {
                    $form->increment('submissions_count');
                    return $existing;
                }
            }

            $lead = Lead::create([
                ...$leadData,
                'tenant_id'   => $form->tenant_id,
                'name'        => $leadData['name'] ?? 'Web Lead',
                'source_id'   => $form->lead_source_id,
                'status_id'   => $form->lead_status_id,
                'assigned_to' => $form->assigned_to,
                'is_public'   => true,
                'created_by'  => $form->created_by,
            ]);

            $lead->logActivity('created', 'Lead captured from web form: ' . $form->name, null, null, $form->created_by);
            $form->increment('submissions_count');

            Log::channel('sales')->info('Web-to-lead submission', [
                'form_id' => $form->id, 'lead_id' => $lead->id, 'tenant_id' => $form->tenant_id,
            ]);

            return $lead;
        });
    }

    private function assertTenant(WebToLeadForm $form, int $tenantId): void
    {
        if ($form->tenant_id !== $tenantId) {
            throw new UnauthorizedTenantException();
        }
    }
}
