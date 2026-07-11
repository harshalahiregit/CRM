<?php

namespace App\Http\Controllers\Api\Helpdesk;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Helpdesk\PublicTicketRequest;
use App\Services\Helpdesk\HelpdeskWidgetService;
use App\Services\Helpdesk\KnowledgeBaseService;
use Illuminate\Http\Request;

/**
 * Public (no-auth) Helpdesk surface: the embeddable support widget and the
 * public knowledge base. Tenant is resolved from the widget public key (browse)
 * or the article's globally-unique share slug (single article).
 */
class PublicHelpdeskController extends Controller
{
    use ApiResponse;

    public function __construct(
        private HelpdeskWidgetService $widget,
        private KnowledgeBaseService $kb,
    ) {
    }

    /* ── Widget ticket submission (rate-limited + honeypot) ────── */
    public function submitTicket(PublicTicketRequest $request, string $key)
    {
        $origin = $request->headers->get('Origin') ?: $request->headers->get('Referer');
        $result = $this->widget->submit($key, $origin, $request->validated());

        return $this->success($result, 'Ticket submitted', 201);
    }

    /* ── Public KB browse (by widget key) ──────────────────────── */
    public function kbTree(Request $request, string $key)
    {
        $tenantId = $this->widget->resolveTenantId($key);
        $departmentId = $request->query('department_id') ? (int) $request->query('department_id') : null;

        return $this->success($this->kb->publicTree($tenantId, $departmentId), 'Knowledge base retrieved');
    }

    public function kbSearch(Request $request, string $key)
    {
        $tenantId = $this->widget->resolveTenantId($key);
        $departmentId = $request->query('department_id') ? (int) $request->query('department_id') : null;

        return $this->success($this->kb->publicSearch($tenantId, $request->query('q'), $departmentId), 'Search results');
    }

    /* ── Public article by share slug (no key needed) ──────────── */
    public function article(string $slug)
    {
        return $this->success($this->kb->publicArticleBySlug($slug), 'Article retrieved');
    }

    public function vote(Request $request, string $slug)
    {
        $data = $request->validate(['direction' => ['required', 'in:up,down']]);

        return $this->success($this->kb->publicVote($slug, $data['direction']), 'Thanks for your feedback');
    }
}
