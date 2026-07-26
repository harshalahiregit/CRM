<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\StatusService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Advanced Status Manager. Reading is open to any authenticated user (every
 * status dropdown in the app needs it); writing is admin-only, enforced here
 * rather than in a FormRequest because all four writes share the same rule.
 */
class StatusController extends Controller
{
    use ApiResponse;

    public function __construct(private StatusService $statuses)
    {
    }

    public function index(Request $request, string $type)
    {
        $this->assertType($type);

        return $this->success($this->statuses->list($type, $request->user()->tenant_id), 'Statuses retrieved');
    }

    public function store(Request $request, string $type)
    {
        $this->assertType($type);
        $this->assertAdmin($request);
        $data = $this->validated($request, $type, true);

        return $this->success($this->statuses->create($type, $data, $request->user()->tenant_id), 'Status created', 201);
    }

    public function update(Request $request, string $type, int $id)
    {
        $this->assertType($type);
        $this->assertAdmin($request);
        $data = $this->validated($request, $type, false);

        return $this->success($this->statuses->update($type, $id, $data, $request->user()->tenant_id), 'Status updated');
    }

    public function destroy(Request $request, string $type, int $id)
    {
        $this->assertType($type);
        $this->assertAdmin($request);
        $this->statuses->delete($type, $id, $request->user()->tenant_id);

        return $this->success(null, 'Status deleted');
    }

    public function reorder(Request $request, string $type)
    {
        $this->assertType($type);
        $this->assertAdmin($request);
        $data = $request->validate(['ordered_ids' => 'present|array', 'ordered_ids.*' => 'integer|min:1']);

        $n = $this->statuses->reorder($type, $data['ordered_ids'], $request->user()->tenant_id);

        return $this->success(['reordered' => $n], 'Order updated');
    }

    private function validated(Request $request, string $type, bool $creating): array
    {
        $keys = $this->statuses->keys($type, $request->user()->tenant_id);

        return $request->validate([
            'name'                => ($creating ? 'required' : 'sometimes').'|string|max:60',
            'color'               => 'nullable|string|max:9',
            'order'               => 'nullable|integer|min:0|max:9999',
            'is_default_filter'   => 'nullable|boolean',
            // Transitions reference other statuses by key.
            'can_be_changed_to'   => 'nullable|array',
            'can_be_changed_to.*' => ['string', Rule::in($keys)],
            'hidden_for'          => 'nullable|array',
            'hidden_for.*'        => ['string', Rule::in(['admin', 'staff', 'client', 'vendor', 'third_party_vendor'])],
        ]);
    }

    private function assertType(string $type): void
    {
        abort_unless(in_array($type, StatusService::TYPES, true), 404);
    }

    private function assertAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === 'admin', 403, 'Only admins can change statuses.');
    }
}
