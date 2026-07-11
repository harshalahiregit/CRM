<?php

namespace App\Http\Controllers\Api\Helpdesk;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Helpdesk\HelpdeskSettingItemRequest;
use App\Http\Requests\Helpdesk\UpdateHelpdeskSettingsRequest;
use App\Services\Helpdesk\HelpdeskSettingsService;
use Illuminate\Http\Request;

/**
 * Admin: Freshdesk-style "Support Settings" — manage priorities, statuses,
 * departments and the public-form settings. GET /settings is readable by any
 * authed user (the ticket form needs the lists); all writes are admin-only
 * (enforced in the FormRequest authorize()).
 */
class HelpdeskSettingsController extends Controller
{
    use ApiResponse;

    public function __construct(private HelpdeskSettingsService $settings)
    {
    }

    /** Everything the ticket form + settings screen need, in one call. */
    public function index(Request $request)
    {
        return $this->success($this->settings->bundle($request->user()->tenant_id), 'Helpdesk settings retrieved');
    }

    private const LABELS = ['priorities' => 'Priority', 'statuses' => 'Status', 'departments' => 'Department'];

    public function storeItem(HelpdeskSettingItemRequest $request, string $type)
    {
        $item = $this->settings->createItem($type, $request->validated(), $request->user()->tenant_id);

        return $this->success($item, (self::LABELS[$type] ?? 'Item').' created', 201);
    }

    public function updateItem(HelpdeskSettingItemRequest $request, string $type, int $id)
    {
        $item = $this->settings->updateItem($type, $id, $request->validated(), $request->user()->tenant_id);

        return $this->success($item, 'Updated');
    }

    public function destroyItem(Request $request, string $type, int $id)
    {
        $this->assertAdmin($request);
        $this->settings->deleteItem($type, $id, $request->user()->tenant_id);

        return $this->success(null, 'Deleted');
    }

    public function reorder(Request $request, string $type)
    {
        $this->assertAdmin($request);
        $ids = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer'])['ids'];
        $this->settings->reorder($type, $ids, $request->user()->tenant_id);

        return $this->success(null, 'Reordered');
    }

    public function updateSettings(UpdateHelpdeskSettingsRequest $request)
    {
        $settings = $this->settings->updateSettings($request->user()->tenant_id, $request->validated());

        return $this->success($settings, 'Settings updated');
    }

    private function assertAdmin(Request $request): void
    {
        if ($request->user()?->role !== 'admin') {
            throw new BusinessException('Only an admin can change helpdesk settings.', 403);
        }
    }
}
