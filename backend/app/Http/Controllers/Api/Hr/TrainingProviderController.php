<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\TrainingProviderService;
use Illuminate\Http\Request;

/**
 * Learning & Development → Training Providers (Phase 1). Thin: validate, delegate,
 * return JSON. Reads open to HR users; writes require HR-queue management.
 * Tenant-scoped, audited.
 */
class TrainingProviderController extends Controller
{
    public function __construct(private TrainingProviderService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->service->list($this->tenant($request), $request->only(['status', 'search', 'provider_type'])));
    }

    public function store(Request $request)
    {
        $this->can($request);

        return response()->json($this->service->create($this->validated($request), $this->tenant($request), $request->user()), 201);
    }

    public function update(Request $request, int $id)
    {
        $this->can($request);

        return response()->json($this->service->update($id, $this->validated($request, true), $this->tenant($request), $request->user()));
    }

    public function updateStatus(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate(['is_active' => 'required|boolean']);

        return response()->json($this->service->setStatus($id, (bool) $data['is_active'], $this->tenant($request), $request->user()));
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'name'           => "$req|string|max:150",
            'code'           => 'nullable|string|max:40',
            'provider_type'  => 'nullable|in:Internal,External',
            'contact_person' => 'nullable|string|max:150',
            'email'          => 'nullable|email|max:150',
            'phone'          => 'nullable|string|max:40',
            'website'        => 'nullable|string|max:200',
            'description'    => 'nullable|string',
            'is_active'      => 'boolean',
        ]);
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage training settings');
    }
}
