<?php

namespace App\Services\Sales;

use App\Exceptions\UnauthorizedTenantException;
use App\Models\Sales\Proposal;
use App\Models\Sales\ProposalTemplate;
use App\Support\HtmlSanitizer;
use Illuminate\Support\Facades\Log;

class ProposalTemplateService
{
    public function __construct(private ContentPageService $contentPages)
    {
    }

    public function list(int $tenantId)
    {
        return ProposalTemplate::where('tenant_id', $tenantId)
            ->with('pages')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function create(array $data, int $tenantId, int $userId): ProposalTemplate
    {
        $pages = $data['pages'] ?? null;
        unset($data['pages']);
        if (isset($data['terms'])) {
            $data['terms'] = HtmlSanitizer::clean($data['terms']);
        }

        $template = ProposalTemplate::create([
            ...$data,
            'tenant_id'  => $tenantId,
            'created_by' => $userId,
        ]);

        if (is_array($pages)) {
            $this->contentPages->syncPages($template, $pages, $tenantId);
        }

        Log::channel('sales')->info('Proposal template created', ['template_id' => $template->id, 'tenant_id' => $tenantId]);

        return $template;
    }

    public function update(ProposalTemplate $template, array $data, int $tenantId): ProposalTemplate
    {
        $this->assertTenant($template, $tenantId);

        $pages = $data['pages'] ?? null;
        $hasPages = array_key_exists('pages', $data);
        unset($data['pages']);
        if (isset($data['terms'])) {
            $data['terms'] = HtmlSanitizer::clean($data['terms']);
        }

        $template->update($data);
        if ($hasPages) {
            $this->contentPages->syncPages($template, $pages ?? [], $tenantId);
        }
        Log::channel('sales')->info('Proposal template updated', ['template_id' => $template->id, 'tenant_id' => $tenantId]);

        return $template->fresh()->load('pages');
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
            'terms'       => $template->terms,
            'date'        => now()->toDateString(),
            'status'      => 'Draft',
        ]);

        // Multi-page content copies 1:1; legacy templates without pages
        // fall back to `content` seeded into notes (LEGACY RULE).
        $this->contentPages->copyPages($template, $proposal, $tenantId);

        Log::channel('sales')->info('Proposal cloned from template', [
            'template_id' => $template->id, 'proposal_id' => $proposal->id, 'tenant_id' => $tenantId,
        ]);

        return $proposal;
    }

    /** B-5: snapshot a proposal's content as a reusable template (content only — line items are out of template scope). */
    public function createFromProposal(Proposal $proposal, string $name, ?string $category, int $tenantId, int $userId): ProposalTemplate
    {
        if ($proposal->tenant_id !== $tenantId) {
            throw new UnauthorizedTenantException();
        }

        $template = ProposalTemplate::create([
            'tenant_id'  => $tenantId,
            'created_by' => $userId,
            'name'       => $name,
            'category'   => $category,
            'content'    => $proposal->notes,
            'terms'      => $proposal->terms,
        ]);
        $this->contentPages->copyPages($proposal, $template, $tenantId);

        Log::channel('sales')->info('Template created from proposal', [
            'proposal_id' => $proposal->id, 'template_id' => $template->id, 'tenant_id' => $tenantId,
        ]);

        return $template->load('pages');
    }

    private function assertTenant(ProposalTemplate $template, int $tenantId): void
    {
        if ($template->tenant_id !== $tenantId) {
            throw new UnauthorizedTenantException();
        }
    }
}
