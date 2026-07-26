<?php

namespace App\Http\Controllers\Api\Helpdesk;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\Helpdesk\CannedResponseService;
use Illuminate\Http\Request;

/**
 * Canned responses (saved replies) — tenant-wide reusable reply snippets that
 * agents insert into the ticket conversation composer.
 */
class CannedResponseController extends Controller
{
    use ApiResponse;

    public function __construct(private CannedResponseService $service)
    {
    }

    public function index(Request $request)
    {
        return $this->success($this->service->list($request->user()->tenant_id), 'Canned responses retrieved');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title'    => 'required|string|max:120',
            'content'  => 'required|string',
            'category' => 'nullable|string|max:60',
            'shortcut' => 'nullable|string|max:40',
        ]);

        return $this->success(
            $this->service->create($data, $request->user()->tenant_id, $request->user()->id),
            'Canned response created',
            201
        );
    }

    public function update(Request $request, int $id)
    {
        $data = $request->validate([
            'title'    => 'sometimes|string|max:120',
            'content'  => 'sometimes|string',
            'category' => 'nullable|string|max:60',
            'shortcut' => 'nullable|string|max:40',
        ]);

        return $this->success($this->service->update($id, $data, $request->user()->tenant_id), 'Canned response updated');
    }

    public function destroy(Request $request, int $id)
    {
        $this->service->delete($id, $request->user()->tenant_id);

        return $this->success(null, 'Canned response deleted');
    }

    public function used(Request $request, int $id)
    {
        return $this->success($this->service->markUsed($id, $request->user()->tenant_id), 'Usage recorded');
    }
}
