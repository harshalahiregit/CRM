<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Models\Shared\MeetingType;
use App\Support\Shared\MeetingTypeCatalog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin management of meeting types + agenda templates (Meeting.docx — admin
 * Types/Templates settings).
 *
 * Reading is open to staff (the pickers need the catalogue); writing is an admin
 * gate, enforced in-controller like the tenant meeting-platform setting. Rows
 * layer over config/meetings.php — see {@see MeetingTypeCatalog}.
 */
class MeetingTypeSettingsController extends Controller
{
    public function __construct(private MeetingTypeCatalog $catalog) {}

    /** The built-in baseline + this tenant's custom/override rows. */
    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        return response()->json([
            // The built-ins, so the UI can show what a new row would override.
            'builtins' => collect(config('meetings.types', []))
                ->map(fn ($label, $key) => [
                    'key' => $key,
                    'label' => $label,
                    'templates' => config("meetings.templates.{$key}", []),
                ])->values(),
            // The tenant's own rows (additions + overrides).
            'custom' => MeetingType::where('tenant_id', $tenantId)
                ->orderBy('sort_order')->orderBy('id')->get(),
            // The merged, effective catalogue actually used by the pickers.
            'effective' => $this->catalog->types($tenantId),
        ]);
    }

    public function store(Request $request)
    {
        $this->assertAdmin($request);
        $tenantId = $request->user()->tenant_id;

        $data = $this->validated($request, $tenantId);

        $type = MeetingType::create([
            'tenant_id' => $tenantId,
            'key' => $data['key'],
            'label' => $data['label'],
            'templates' => $data['templates'] ?? [],
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);
        $this->catalog->forget($tenantId);

        return response()->json($type, 201);
    }

    public function update(Request $request, MeetingType $meetingType)
    {
        $this->assertAdmin($request);
        $this->assertTenant($request, $meetingType);
        $tenantId = $request->user()->tenant_id;

        $data = $this->validated($request, $tenantId, $meetingType->id);

        $meetingType->update([
            'key' => $data['key'],
            'label' => $data['label'],
            'templates' => $data['templates'] ?? [],
            'is_active' => $data['is_active'] ?? $meetingType->is_active,
            'sort_order' => $data['sort_order'] ?? $meetingType->sort_order,
        ]);
        $this->catalog->forget($tenantId);

        return response()->json($meetingType->fresh());
    }

    public function destroy(Request $request, MeetingType $meetingType)
    {
        $this->assertAdmin($request);
        $this->assertTenant($request, $meetingType);

        $meetingType->delete();
        $this->catalog->forget($request->user()->tenant_id);

        return response()->json(['message' => 'Meeting type removed. The built-in default (if any) applies again.']);
    }

    /** @return array<string,mixed> */
    private function validated(Request $request, int $tenantId, ?int $ignoreId = null): array
    {
        return $request->validate([
            // Lower snake key; unique per tenant. Only letters/digits/underscore so
            // it is safe as a config-style key and in an `in:` rule.
            'key' => [
                'required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('meeting_types', 'key')->where('tenant_id', $tenantId)->ignore($ignoreId),
            ],
            'label' => 'required|string|max:120',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:9999',
            'templates' => 'nullable|array',
            'templates.*.item' => 'required|string|max:255',
            'templates.*.duration_minutes' => 'nullable|integer|min:1|max:1440',
            'templates.*.priority' => 'nullable|string|in:'.implode(',', config('meetings.priorities', ['Low', 'Medium', 'High'])),
        ]);
    }

    private function assertAdmin(Request $request): void
    {
        abort_unless($request->user()->role === 'admin', 403, 'Only an admin can manage meeting types.');
    }

    private function assertTenant(Request $request, MeetingType $type): void
    {
        abort_unless((int) $type->tenant_id === (int) $request->user()->tenant_id, 404, 'Meeting type not found.');
    }
}
