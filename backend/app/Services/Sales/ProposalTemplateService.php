<?php

namespace App\Services\Sales;

use App\Exceptions\UnauthorizedTenantException;
use App\Models\Sales\Proposal;
use App\Models\Sales\ProposalTemplate;
use App\Support\CoverSanitizer;
use App\Support\HtmlSanitizer;
use Illuminate\Support\Facades\Log;

class ProposalTemplateService
{
    public function __construct(private ContentPageService $contentPages)
    {
    }

    /**
     * Templates for the gallery.
     *
     * `usage_count` is the number of proposals built from each one — the link
     * already existed on proposals.template_id but was never surfaced, so there
     * was no way to tell a workhorse template from one nobody has ever picked.
     * Counted with withCount so the list stays one query rather than N.
     */
    public function list(int $tenantId)
    {
        return ProposalTemplate::where('tenant_id', $tenantId)
            ->with('pages')
            ->withCount(['proposals as usage_count', 'pages as pages_count'])
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /** One template with its pages — the editor used to fetch the whole list to find it. */
    public function show(ProposalTemplate $template, int $tenantId): ProposalTemplate
    {
        $this->assertTenant($template, $tenantId);

        return $template->load('pages');
    }

    /**
     * Copy a template into a new template.
     *
     * "Use Template" produces a PROPOSAL, so building a variant of an existing
     * template meant retyping every page. This copies the content instead, and
     * never inherits `is_default` — there is only one default.
     */
    public function duplicate(ProposalTemplate $template, int $tenantId, int $userId): ProposalTemplate
    {
        $this->assertTenant($template, $tenantId);

        $copy = ProposalTemplate::create([
            'tenant_id'   => $tenantId,
            'created_by'  => $userId,
            'name'        => $this->uniqueCopyName($template->name, $tenantId),
            'description' => $template->description,
            'category'    => $template->category,
            'content'     => $template->content,
            'terms'       => $template->terms,
            'cover'       => $template->cover,
            'sort_order'  => $template->sort_order,
            'is_default'  => false,
        ]);

        $this->contentPages->copyPages($template, $copy, $tenantId);

        Log::channel('sales')->info('Proposal template duplicated', [
            'from' => $template->id, 'to' => $copy->id, 'tenant_id' => $tenantId,
        ]);

        return $copy->load('pages');
    }

    /** "Pitch deck" → "Pitch deck (copy)", then "(copy 2)" and so on. */
    private function uniqueCopyName(string $name, int $tenantId): string
    {
        $base = preg_replace('/ \(copy(?: \d+)?\)$/', '', $name);
        $candidate = "{$base} (copy)";

        for ($i = 2; ProposalTemplate::where('tenant_id', $tenantId)->where('name', $candidate)->exists(); $i++) {
            $candidate = "{$base} (copy {$i})";
        }

        return $candidate;
    }

    public function create(array $data, int $tenantId, int $userId): ProposalTemplate
    {
        $pages = $data['pages'] ?? null;
        unset($data['pages']);
        if (isset($data['terms'])) {
            $data['terms'] = HtmlSanitizer::clean($data['terms']);
        }
        if (array_key_exists('cover', $data)) {
            $data['cover'] = CoverSanitizer::clean($data['cover']);
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
        if (array_key_exists('cover', $data)) {
            $data['cover'] = CoverSanitizer::clean($data['cover']);
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
            'cover'       => $template->cover,
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
            'cover'      => $proposal->cover,
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
