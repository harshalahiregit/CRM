<?php

namespace App\Http\Controllers\Api\Project;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\Project\ProjectInvoiceService;
use App\Services\Project\ProjectService;
use Illuminate\Http\Request;

/**
 * "Invoice Project" — generate and list project invoice drafts. Read is allowed
 * for anyone who can see the project; generating is a manage action (admin or the
 * project's creator), mirroring how the rest of the Projects module gates writes.
 */
class ProjectInvoiceController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ProjectInvoiceService $invoices,
        private ProjectService $projects,
    ) {
    }

    private function isAdmin(Request $request): bool
    {
        return $request->user()?->role === 'admin';
    }

    public function index(Request $request, int $project)
    {
        $this->projects->assertProjectVisible($project, $request->user()->tenant_id, $request->user()->id, $this->isAdmin($request));

        return $this->success(
            $this->invoices->list($project, $request->user()->tenant_id),
            'Project invoices retrieved',
        );
    }

    public function generate(Request $request, int $project)
    {
        $this->projects->assertProjectManage($project, $request->user()->tenant_id, $request->user()->id, $this->isAdmin($request));

        $invoice = $this->invoices->generate($project, $request->user()->tenant_id, $request->user()->id);

        return $this->success($invoice, 'Invoice generated', 201);
    }
}
