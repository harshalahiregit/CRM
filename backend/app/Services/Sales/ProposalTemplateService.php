<?php

namespace App\Services\Sales;

use App\Exceptions\UnauthorizedTenantException;
use App\Models\Sales\Proposal;
use App\Models\Sales\ProposalTemplate;
use Illuminate\Support\Facades\Log;

class ProposalTemplateService
{
    public function list(int $tenantId)
    {
        return ProposalTemplate::where('tenant_id', $tenantId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function create(array $data, int $tenantId, int $userId): ProposalTemplate
    {
        $template = ProposalTemplate::create([
            ...$data,
            'tenant_id'  => $tenantId,
            'created_by' => $userId,
        ]);

        Log::channel('sales')->info('Proposal template created', ['template_id' => $template->id, 'tenant_id' => $tenantId]);

        return $template;
    }

    public function update(ProposalTemplate $template, array $data, int $tenantId): ProposalTemplate
    {
        $this->assertTenant($template, $tenantId);
        $template->update($data);
        Log::channel('sales')->info('Proposal template updated', ['template_id' => $template->id, 'tenant_id' => $tenantId]);

        return $template->fresh();
    }

    public function delete(ProposalTemplate $template, int $tenantId): void
    {
        $this->assertTenant($template, $tenantId);
        $template->delete();
        Log::channel('sales')->info('Proposal template deleted', ['template_id' => $template->id, 'tenant_id' => $tenantId]);
    }

    /**
     * Clone a template into a new Draft proposal. Only seeds subject/
     * notes/template_id — line items, client, and dates are left for
     * the user to fill in via the normal proposal edit flow, since a
     * template's `content` field is free-form (rich-text/JSON) rather
     * than a structured line-item list.
     */
    public function cloneToProposal(ProposalTemplate $template, int $tenantId, int $userId): Proposal
    {
        $this->assertTenant($template, $tenantId);

        $proposal = Proposal::create([
            'tenant_id'   => $tenantId,
            'created_by'  => $userId,
            'template_id' => $template->id,
            'subject'     => $template->name,
            'notes'       => $template->content,
            'date'        => now()->toDateString(),
            'status'      => 'Draft',
        ]);

        Log::channel('sales')->info('Proposal cloned from template', [
            'template_id' => $template->id, 'proposal_id' => $proposal->id, 'tenant_id' => $tenantId,
        ]);

        return $proposal;
    }

    private function assertTenant(ProposalTemplate $template, int $tenantId): void
    {
        if ($template->tenant_id !== $tenantId) {
            throw new UnauthorizedTenantException();
        }
    }
}
