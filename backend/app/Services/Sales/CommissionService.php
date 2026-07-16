<?php

namespace App\Services\Sales;

use App\Exceptions\UnauthorizedTenantException;
use App\Models\Sales\CommissionEntry;
use App\Models\Sales\CommissionRule;
use App\Models\Sales\Lead;
use App\Models\Sales\SalesInvoice;
use Illuminate\Support\Facades\Log;

class CommissionService
{
    /* ── Rules CRUD ─────────────────────────────────────────────── */
    public function rules(int $tenantId)
    {
        return CommissionRule::forTenant($tenantId)->latest()->get();
    }

    public function createRule(array $data, int $tenantId, int $userId): CommissionRule
    {
        return CommissionRule::create([...$data, 'tenant_id' => $tenantId, 'created_by' => $userId]);
    }

    public function updateRule(CommissionRule $rule, array $data, int $tenantId): CommissionRule
    {
        $this->assertTenant($rule, $tenantId);
        $rule->update($data);
        return $rule->fresh();
    }

    public function deleteRule(CommissionRule $rule, int $tenantId): void
    {
        $this->assertTenant($rule, $tenantId);
        $rule->delete();
    }

    /* ── Entries ────────────────────────────────────────────────── */
    public function entries(int $tenantId, array $filters)
    {
        $query = CommissionEntry::forTenant($tenantId)->with(['rule:id,name', 'staff:id,name'])->latest();

        if (!empty($filters['status']))        $query->where('status', $filters['status']);
        if (!empty($filters['payout_status'])) $query->where('payout_status', $filters['payout_status']);
        if (!empty($filters['staff_id']))      $query->where('staff_id', $filters['staff_id']);

        return $query->get();
    }

    public function summary(int $tenantId)
    {
        $entries = CommissionEntry::forTenant($tenantId)->with('staff:id,name')->get();

        return $entries->groupBy('staff_id')->map(function ($group) {
            // 'total' excludes rejected entries so it reflects real payout
            // liability, not gross of rejections.
            $active = $group->where('status', '!=', 'rejected');
            return [
                'staff'     => $group->first()->staff?->name ?? 'Unassigned',
                'total'     => round($active->sum('commission_amount'), 2),
                'approved'  => round($group->where('status', 'approved')->sum('commission_amount'), 2),
                'paid'      => round($group->where('payout_status', 'paid')->sum('commission_amount'), 2),
                'count'     => $group->count(),
            ];
        })->values();
    }

    public function approve(CommissionEntry $entry, int $tenantId, int $userId): CommissionEntry
    {
        $this->assertEntryTenant($entry, $tenantId);
        $entry->update(['status' => 'approved', 'approved_by' => $userId, 'approved_at' => now()]);
        return $entry->fresh();
    }

    public function reject(CommissionEntry $entry, int $tenantId, int $userId): CommissionEntry
    {
        $this->assertEntryTenant($entry, $tenantId);
        $entry->update(['status' => 'rejected', 'approved_by' => $userId, 'approved_at' => now()]);
        return $entry->fresh();
    }

    public function markPaid(CommissionEntry $entry, int $tenantId): CommissionEntry
    {
        $this->assertEntryTenant($entry, $tenantId);
        $entry->update(['payout_status' => 'paid', 'paid_at' => now()]);
        return $entry->fresh();
    }

    /* ── Auto-computation hooks (idempotent) ────────────────────── */

    /**
     * Called when an invoice becomes fully paid (from InvoiceService::recordPayment
     * and the credit-note application path). Generates one entry per matching
     * active rule; the unique (rule, source) constraint makes repeat calls no-ops.
     */
    public function computeForInvoice(SalesInvoice $invoice): void
    {
        if ($invoice->status !== 'Paid') return;

        $rules = CommissionRule::forTenant($invoice->tenant_id)->active()
            ->whereIn('applies_to', ['paid_invoice', 'both'])->get();

        foreach ($rules as $rule) {
            $this->makeEntry($rule, $invoice, SalesInvoice::class, (float) $invoice->total, $invoice->sale_agent);
        }
    }

    /**
     * Called from LeadService::convert when a lead is won/converted.
     */
    public function computeForWonLead(Lead $lead): void
    {
        $rules = CommissionRule::forTenant($lead->tenant_id)->active()
            ->whereIn('applies_to', ['won_deal', 'both'])->get();

        foreach ($rules as $rule) {
            $this->makeEntry($rule, $lead, Lead::class, (float) $lead->lead_value, $lead->assigned_to);
        }
    }

    private function makeEntry(CommissionRule $rule, $source, string $sourceType, float $base, ?int $staffId): void
    {
        // Condition gate: min_amount (region/product bases are parked — no columns).
        $minAmount = (float) ($rule->conditions['min_amount'] ?? 0);
        if ($base < $minAmount) return;

        $commission = $rule->calc_type === 'flat'
            ? (float) $rule->rate
            : round($base * (float) $rule->rate / 100, 2);

        // firstOrCreate on the unique key = idempotent; never double-generates.
        CommissionEntry::firstOrCreate(
            [
                'rule_id'     => $rule->id,
                'source_type' => $sourceType,
                'source_id'   => $source->id,
            ],
            [
                'tenant_id'         => $rule->tenant_id,
                'staff_id'          => $staffId,
                'base_amount'       => $base,
                'commission_amount' => $commission,
                'status'            => 'pending',
                'payout_status'     => 'unpaid',
            ],
        );
    }

    /* ── Helpers ────────────────────────────────────────────────── */
    private function assertTenant(CommissionRule $rule, int $tenantId): void
    {
        if ($rule->tenant_id !== $tenantId) throw new UnauthorizedTenantException();
    }

    private function assertEntryTenant(CommissionEntry $entry, int $tenantId): void
    {
        if ($entry->tenant_id !== $tenantId) throw new UnauthorizedTenantException();
    }
}
