<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreTaskStatusRequest;
use App\Models\Sales\TaskStatus;
use App\Services\Sales\TaskService;
use App\Exceptions\UnauthorizedTenantException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskStatusController extends Controller
{
    public function __construct(private TaskService $taskService)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->taskService->statuses($request->user()->tenant_id));
    }

    public function store(StoreTaskStatusRequest $request)
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validated();
        $status = DB::transaction(function () use ($data, $tenantId) {
            // Only one default per tenant — clear the others first.
            if (!empty($data['is_default'])) {
                TaskStatus::forTenant($tenantId)->update(['is_default' => false]);
            }
            return TaskStatus::create([...$data, 'tenant_id' => $tenantId]);
        });
        return response()->json($status, 201);
    }

    public function update(StoreTaskStatusRequest $request, TaskStatus $taskStatus)
    {
        $tenantId = $request->user()->tenant_id;
        $this->assertTenant($taskStatus, $tenantId);
        $data = $request->validated();
        DB::transaction(function () use ($data, $taskStatus, $tenantId) {
            if (!empty($data['is_default'])) {
                TaskStatus::forTenant($tenantId)->where('id', '!=', $taskStatus->id)->update(['is_default' => false]);
            }
            $taskStatus->update($data);
        });
        return response()->json($taskStatus->fresh());
    }

    public function destroy(Request $request, TaskStatus $taskStatus)
    {
        $this->assertTenant($taskStatus, $request->user()->tenant_id);
        $taskStatus->delete();
        return response()->json(['message' => 'Deleted']);
    }

    private function assertTenant(TaskStatus $status, int $tenantId): void
    {
        if ($status->tenant_id !== $tenantId) {
            throw new UnauthorizedTenantException();
        }
    }
}
