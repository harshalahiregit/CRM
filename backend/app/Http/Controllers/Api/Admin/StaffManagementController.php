<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\SessionService;
use App\Services\Hr\EmployeeIdentityService;
use Illuminate\Support\Facades\DB;
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

            // Counts cover the same population the list shows, admins included —
            // a headcount that silently omits them contradicts the screen beside it.
            $totalStaff = $this->manageable($tenantId)->count();

            $activeStaff = $this->manageable($tenantId)->where('status', 'active')->count();

            $inactiveStaff = $this->manageable($tenantId)->whereIn('status', ['inactive', 'suspended'])->count();

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

            $query = $this->manageable($tenantId);

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

        $staff = $this->manageable($tenantId)
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

        // The login and the employee record are written together or not at all.
        // Staff Management creates the PERSON — the old CRM's tblstaff simply is
        // the person, and HR extends it. Sangoe keeps two tables because
        // hr_employees carries probation, shift and salary that a login has no
        // business holding, but they must behave as one record. Without the
        // transaction a half-failure leaves a login no HR screen can see and that
        // cannot clock in, which is the state every admin account is in today.
        [$staff, $employee] = DB::transaction(function () use ($request, $tenantId) {
        $staff = User::create([
            'tenant_id'     => $tenantId,
            'name'          => $request->name,
            'email'         => $request->email,
            'phone'         => $request->phone,
            'password'      => Hash::make($request->password),
            'internal_role' => $request->internal_role,
            'department'    => $request->department,
            'designation'   => $request->designation,
            'status'        => $request->status,
            // The role is NEVER taken from the request body. It defaults to staff
            // and is raised to admin only when the person doing it is already an
            // admin — the old CRM's shape exactly (Staff_model.php:414-421, which
            // sets admin = 0 first and only then consults is_admin()).
            'role'          => $this->resolvedRole($request),
            'meta'          => $this->sanitiseMeta($request->meta ?? []),
        ]);

            $employee = app(EmployeeIdentityService::class)->provisionEmployeeFor($staff, [
                'department'  => $request->department,
                'designation' => $request->designation,
                'phone'       => $request->phone,
            ], $request->user());

            return [$staff, $employee];
        });

        // Returned as a sibling rather than a relation: User has no employee
        // relation, and adding one means changing a model three modules share.
        return response()->json([
            'status'  => 'success',
            'message' => 'Staff member created successfully',
            'data'    => $staff,
            'employee' => [
                'id'            => $employee->id,
                'employee_code' => $employee->employee_code,
            ],
        ], 201);
    }

    /**
     * Update staff member
     */
    public function update(Request $request, $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $staff = $this->manageable($tenantId)
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

        // Promotion and demotion. Only an admin may change this at all, and the
        // field is read from the request only after that check — never merged in
        // with the rest, so it cannot ride along in a payload from someone else.
        if ($request->has('administrator') && $request->user()->role === 'admin') {
            $wantsAdmin = $request->boolean('administrator');

            if ($error = $this->roleChangeError($request, $staff, $wantsAdmin)) {
                return response()->json(['status' => 'error', 'message' => $error], 422);
            }

            if ($wantsAdmin !== ($staff->role === 'admin')) {
                $updateData['role'] = $wantsAdmin ? 'admin' : 'staff';

                app(\App\Services\AuditLogService::class)->record(
                    $staff,
                    $wantsAdmin ? 'Promoted to Administrator' : 'Administrator Access Removed',
                    $request->user(),
                    null,
                    ['from' => $staff->role, 'to' => $updateData['role']]
                );
            }
        }

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
     * The role a newly created account gets.
     *
     * `administrator` is a boolean on the form, not a role string, and it is only
     * honoured for an actor who is already an admin. A non-admin sending it gets
     * staff — silently, exactly as the old CRM does, because a rejected request
     * would tell an attacker the field exists.
     */
    private function resolvedRole(Request $request): string
    {
        return ($request->boolean('administrator') && $request->user()->role === 'admin')
            ? 'admin'
            : 'staff';
    }

    /**
     * Apply a promotion or demotion, or explain why it cannot happen.
     *
     * Two refusals, both taken from the old CRM:
     *
     *   cant_remove_yourself_from_admin — you cannot demote yourself, so nobody
     *   can lock themselves out of the screen that would fix it.
     *
     *   cant_remove_main_admin — the founding admin stays. Perfex pins staff #1;
     *   here it is the earliest admin in the tenant, so a workspace can never be
     *   left with nobody in charge.
     *
     * @return string|null  an error message, or null when the change is allowed
     */
    private function roleChangeError(Request $request, User $target, bool $wantsAdmin): ?string
    {
        $isAdmin = $target->role === 'admin';

        if ($wantsAdmin === $isAdmin) {
            return null;   // nothing is changing
        }

        if ($wantsAdmin) {
            return null;   // promotion; the actor is already known to be an admin
        }

        if ((int) $target->id === (int) $request->user()->id) {
            return 'You cannot remove your own administrator access.';
        }

        if ((int) $target->id === $this->foundingAdminId($target->tenant_id)) {
            return 'The founding administrator cannot be demoted.';
        }

        return null;
    }

    /**
     * Who Staff Management manages: staff AND admins.
     *
     * This used to be `where('role', 'staff')` in ten places, which meant admins
     * were invisible on the screen — you could not see that a second one existed,
     * let alone who they were. The old CRM lists administrators alongside everyone
     * else with a flag on the row, and answering "who are the admins" from the
     * screen matters more the more of them there are.
     *
     * Portal identities (client, vendor, third_party_vendor, company) stay out:
     * they are not people who work here.
     */
    private function manageable(int $tenantId)
    {
        return User::where('tenant_id', $tenantId)->whereIn('role', ['staff', 'admin']);
    }

    /**
     * The founding admin of a tenant — the account created with the company.
     *
     * The old CRM pins staff #1 and refuses to demote them (cant_remove_main_admin),
     * so a workspace can never be left with nobody in charge. The equivalent here
     * is the earliest admin in the tenant, which is per-tenant rather than a global
     * row id.
     */
    private function foundingAdminId(int $tenantId): ?int
    {
        return User::where('tenant_id', $tenantId)->where('role', 'admin')->min('id');
    }

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

        $staff = $this->manageable($tenantId)
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

        $staff = $this->manageable($tenantId)
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
            $existingRoles = $this->manageable($tenantId)
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
            $existingDepts = $this->manageable($tenantId)
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
