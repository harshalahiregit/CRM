<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\SessionService;
use App\Support\Hr\StaffPermission;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class StaffManagementController extends Controller
{
    /**
     * Get staff dashboard statistics
     */
    public function stats(Request $request): JsonResponse
    {
        try {
            \Log::info('Stats endpoint called', [
                'user' => $request->user() ? $request->user()->id : 'none',
                'tenant' => $request->user() ? $request->user()->tenant_id : 'none'
            ]);

            $tenantId = $request->user()->tenant_id;

            $totalStaff = User::where('tenant_id', $tenantId)
                ->where('role', 'staff')
                ->count();

            $activeStaff = User::where('tenant_id', $tenantId)
                ->where('role', 'staff')
                ->where('status', 'active')
                ->count();

            $inactiveStaff = User::where('tenant_id', $tenantId)
                ->where('role', 'staff')
                ->whereIn('status', ['inactive', 'suspended'])
                ->count();

            \Log::info('Stats calculated successfully', [
                'total' => $totalStaff,
                'active' => $activeStaff,
                'inactive' => $inactiveStaff
            ]);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'total_staff' => $totalStaff,
                    'active_staff' => $activeStaff,
                    'inactive_staff' => $inactiveStaff,
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Stats endpoint error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all staff members with filters
     */
    public function index(Request $request): JsonResponse
    {
        try {
            \Log::info('Index endpoint called', [
                'user' => $request->user() ? $request->user()->id : 'none',
                'params' => $request->all()
            ]);

            $tenantId = $request->user()->tenant_id;

            $query = User::where('tenant_id', $tenantId)
                ->where('role', 'staff');

            // Search filter
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }

            // Designation filter
            if ($request->has('designation') && $request->designation) {
                $query->where('internal_role', $request->designation);
            }

            // Status filter
            if ($request->has('status') && $request->status) {
                $query->where('status', $request->status);
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            
            if ($sortBy === 'last_active') {
                $query->orderBy('updated_at', $sortOrder);
            } else {
                $query->orderBy($sortBy, $sortOrder);
            }

            // Pagination
            $perPage = $request->get('per_page', 15);
            $staff = $query->paginate($perPage);

            \Log::info('Staff fetched successfully', [
                'count' => $staff->count(),
                'total' => $staff->total()
            ]);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'staff' => $staff->items(),
                    'pagination' => [
                        'current_page' => $staff->currentPage(),
                        'last_page' => $staff->lastPage(),
                        'per_page' => $staff->perPage(),
                        'total' => $staff->total(),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Index endpoint error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get single staff member details
     */
    public function show(Request $request, $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $staff = User::where('tenant_id', $tenantId)
            ->where('role', 'staff')
            ->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $staff,
        ]);
    }

    /**
     * Create new staff member
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name'          => 'required|string|min:2|max:100',
            'email'         => 'required|email|unique:users,email',
            'phone'         => 'nullable|string|max:20',
            'password'      => ['required', Password::min(8)],
            'internal_role' => 'required|string|max:50',
            'department'    => 'nullable|string|max:100',
            'designation'   => 'nullable|string|max:100',
            'status'        => 'required|in:active,inactive,suspended',
            'meta'          => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $tenantId = $request->user()->tenant_id;

        $staff = User::create([
            'tenant_id'     => $tenantId,
            'name'          => $request->name,
            'email'         => $request->email,
            'phone'         => $request->phone,
            'password'      => Hash::make($request->password),
            'role'          => 'staff',
            'internal_role' => $request->internal_role,
            'department'    => $request->department,
            'designation'   => $request->designation,
            'status'        => $request->status,
            'meta'          => $this->sanitiseMeta($request->meta ?? []),
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Staff member created successfully',
            'data'    => $staff,
        ], 201);
    }

    /**
     * Update staff member
     */
    public function update(Request $request, $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $staff = User::where('tenant_id', $tenantId)
            ->where('role', 'staff')
            ->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name'          => 'sometimes|required|string|min:2|max:100',
            'email'         => 'sometimes|required|email|unique:users,email,' . $id,
            'phone'         => 'nullable|string|max:20',
            'password'      => ['nullable', Password::min(8)],
            'internal_role' => 'sometimes|required|string|max:50',
            'department'    => 'nullable|string|max:100',
            'designation'   => 'nullable|string|max:100',
            'status'        => 'sometimes|required|in:active,inactive,suspended',
            'meta'          => 'nullable|array',
            // ST1 — the sender identity outgoing mail goes out as. Set HERE, by an
            // admin, and not on the user's own profile: TenantMailer uses this
            // verbatim as the From address, so a self-service field let any signed-in
            // user send CRM mail as anyone — a colleague, a director, a customer —
            // with nothing but a well-formed-email check in the way.
            'mail_from_name'  => 'nullable|string|max:120',
            'mail_from_email' => 'nullable|email|max:191',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $updateData = $request->only([
            'name', 'email', 'phone', 'internal_role',
            'department', 'designation', 'status',
            'mail_from_name', 'mail_from_email',
        ]);

        // Merge meta (preserve existing keys not in new payload)
        if ($request->has('meta')) {
            $updateData['meta'] = $this->sanitiseMeta(array_merge($staff->meta ?? [], $request->meta));
        }

        // Only update password if provided
        if ($request->filled('password')) {
            $updateData['password'] = Hash::make($request->password);
        }

        $staff->update($updateData);

        return response()->json([
            'status'  => 'success',
            'message' => 'Staff member updated successfully',
            'data'    => $staff->fresh(),
        ]);
    }

    /**
     * Toggle staff status (active/inactive)
     */
    /**
     * `meta` is validated only as `nullable|array`, so anything at all can be
     * posted into it — including permission keys naming modules that do not
     * exist. A forged or stale module cannot grant access (StaffPermissionService
     * refuses unknown modules), but leaving it in the column makes the grid render
     * rows nobody can turn off, and makes the stored data a poor record of who can
     * do what. Filtered on the way in so the column stays truthful.
     */
    private function sanitiseMeta(array $meta): array
    {
        if (array_key_exists('permissions', $meta)) {
            $meta['permissions'] = StaffPermission::sanitise($meta['permissions']);
        }

        return $meta;
    }

    public function toggleStatus(Request $request, $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $staff = User::where('tenant_id', $tenantId)
            ->where('role', 'staff')
            ->findOrFail($id);

        $newStatus = $staff->status === 'active' ? 'inactive' : 'active';
        $staff->update(['status' => $newStatus]);

        // Deactivating has to end the sessions that already exist, not just stop
        // the next sign-in. Sanctum tokens carry no status check of their own, so
        // without this a deactivated person keeps working until they happen to
        // log out — which is exactly when nobody is watching. CompanyAdminService
        // already does this on its own deactivate path; this one did not.
        $revoked = 0;
        if ($newStatus === 'inactive') {
            $revoked = app(SessionService::class)->forceLogout($staff, $request->user());
        }

        return response()->json([
            'status' => 'success',
            'message' => $newStatus === 'inactive'
                ? "Staff member deactivated and signed out of {$revoked} " . ($revoked === 1 ? 'session' : 'sessions')
                : 'Staff member reactivated',
            'data' => [
                'id' => $staff->id,
                'status' => $staff->status,
                'sessions_revoked' => $revoked,
            ],
        ]);
    }

    /**
     * Delete staff member
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $staff = User::where('tenant_id', $tenantId)
            ->where('role', 'staff')
            ->findOrFail($id);

        // Prevent deleting staff if they have active assignments
        // You can add additional checks here based on your business logic

        $staff->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Staff member deleted successfully',
        ]);
    }

    /**
     * Get available designations/internal roles
     */
    public function designations(Request $request): JsonResponse
    {
        try {
            \Log::info('Designations endpoint called');
            
            $tenantId = $request->user()->tenant_id;

            // Get unique internal roles from existing staff
            $existingRoles = User::where('tenant_id', $tenantId)
                ->where('role', 'staff')
                ->whereNotNull('internal_role')
                ->distinct()
                ->pluck('internal_role')
                ->toArray();

            // Predefined roles
            $predefinedRoles = [
                'hr_executive' => 'HR Executive',
                'hiring_manager' => 'Hiring Manager',
                'team_lead' => 'Team Lead',
                'project_manager' => 'Project Manager',
                'department_head' => 'Department Head',
                'senior_executive' => 'Senior Executive',
                'junior_executive' => 'Junior Executive',
            ];

            // Merge existing roles with predefined ones
            $allRoles = [];
            foreach ($predefinedRoles as $key => $label) {
                $allRoles[] = ['value' => $key, 'label' => $label];
            }

            // Add any custom roles that exist in the database
            foreach ($existingRoles as $role) {
                if (!isset($predefinedRoles[$role])) {
                    $allRoles[] = [
                        'value' => $role,
                        'label' => ucwords(str_replace('_', ' ', $role)),
                    ];
                }
            }

            \Log::info('Designations fetched', ['count' => count($allRoles)]);

            return response()->json([
                'status' => 'success',
                'data' => $allRoles,
            ]);
        } catch (\Exception $e) {
            \Log::error('Designations endpoint error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get available departments
     */
    public function departments(Request $request): JsonResponse
    {
        try {
            \Log::info('Departments endpoint called');
            
            $tenantId = $request->user()->tenant_id;

            // Get unique departments from existing staff
            $existingDepts = User::where('tenant_id', $tenantId)
                ->where('role', 'staff')
                ->whereNotNull('department')
                ->distinct()
                ->pluck('department')
                ->toArray();

            // Predefined departments
            $predefinedDepts = [
                'HR',
                'Engineering',
                'Sales',
                'Marketing',
                'Finance',
                'Operations',
                'Product',
                'Customer Support',
            ];

            // Merge and remove duplicates
            $allDepts = array_unique(array_merge($predefinedDepts, $existingDepts));
            sort($allDepts);

            \Log::info('Departments fetched', ['count' => count($allDepts)]);

            return response()->json([
                'status' => 'success',
                'data' => $allDepts,
            ]);
        } catch (\Exception $e) {
            \Log::error('Departments endpoint error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
