<?php

namespace Sire\Http\Controllers;

use Sire\Http\Controllers\Concerns\AssertsSireTenantOwnership;
use Sire\Http\Controllers\Concerns\ResolvesSireUser;
use Sire\Http\Controllers\Concerns\SireApiResponse;
use Sire\Models\KbLink;
use Sire\Models\Report;
use Sire\Services\SireKnowledgeLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Links to the EXISTING Helpdesk knowledge base. SIRE has no KB of its own. */
class SireKbLinkController extends SireController
{
    use SireApiResponse;
    use ResolvesSireUser;
    use AssertsSireTenantOwnership;

    public function __construct(private readonly SireKnowledgeLinkService $kb)
    {
    }

    public function index(Request $request, Report $report): JsonResponse
    {
        $this->assertTenantOwnership($report);

        return $this->success($report->kbLinks()->with('creator:id,name')->get());
    }

    public function store(Request $request, Report $report): JsonResponse
    {
        $this->assertTenantOwnership($report);

        $data = $request->validate([
            'kb_article_id' => ['required', 'integer'],
            'link_type'     => ['required', Rule::in(KbLink::TYPES)],
        ]);

        return $this->success(
            $this->kb->link($report, (int) $data['kb_article_id'], $data['link_type'], $this->sireUser()),
            201,
        );
    }

    /** Draft a new article from this issue. Created as a draft, never published. */
    public function createArticle(Request $request, Report $report): JsonResponse
    {
        $this->assertTenantOwnership($report);

        $data = $request->validate([
            'title'     => ['nullable', 'string', 'max:255'],
            'summary'   => ['nullable', 'string', 'max:1000'],
            'body'      => ['nullable', 'string', 'max:100000'],
            'link_type' => ['nullable', Rule::in(KbLink::TYPES)],
        ]);

        return $this->success($this->kb->createArticleFrom($report, $data, $this->sireUser()), 201);
    }

    public function destroy(Request $request, KbLink $link): JsonResponse
    {
        $this->assertTenantOwnership($link);
        $this->kb->unlink($link, $this->sireUser());

        return $this->success(null, 204);
    }
}
