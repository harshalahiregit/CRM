<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shared\Note;
use App\Services\Shared\NoteService;
use App\Models\StaffRole;
use App\Models\User;
use App\Services\Auth\SessionService;
use App\Services\Auth\StaffRoleService;
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
            // Optional so the old internal_role-only payload still works. When a
            // role IS sent it wins, and its slug is written to internal_role so
            // the two can never disagree.
            'staff_role_id' => 'nullable|integer|exists:staff_roles,id',
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

            // Inside the transaction: a login created without the role it was
            // meant to have is the same half-record the transaction exists to
            // prevent.
            if ($request->filled('staff_role_id')) {
                $role = StaffRole::where('tenant_id', $tenantId)->find($request->staff_role_id);
                if ($role) {
                    app(StaffRoleService::class)->assign($staff, $role);
                    $staff->refresh();
                }
            }

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
            'staff_role_id' => 'nullable|integer|exists:staff_roles,id',
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

        // The permission role. Assigned through the service so internal_role is
        // written from its slug in the same breath — a user whose role says
        // Accounts but whose internal_role says otherwise would be able to
        // approve advances or not depending on which check happened to run.
        if ($request->has('staff_role_id')) {
            $role = $request->filled('staff_role_id')
                ? StaffRole::where('tenant_id', $staff->tenant_id)->find($request->staff_role_id)
                : null;

            app(StaffRoleService::class)->assign($staff, $role);
            $staff->refresh();

            // Already written by the service; letting it through again would
            // overwrite the slug with whatever the form happened to hold.
            unset($updateData['internal_role']);
        }

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
    /* ── Account: how this login is actually being used ──────────────── */

    /**
     * Sign-in facts and live sessions.
     *
     * All of it already existed — last_login_at, last_login_ip and user_sessions
     * are written on every login — and none of it was reachable from the staff
     * screen, so "is this account still being used" could only be answered from
     * the database.
     */
    public function account(Request $request, int $id): JsonResponse
    {
        $staff = $this->manageable($request->user()->tenant_id)->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data'   => [
                'last_login_at' => $staff->last_login_at,
                'last_login_ip' => $staff->last_login_ip,
                'created_at'    => $staff->created_at,
                'status'        => $staff->status,
                // Only live ones. A revoked session is history, and mixing the
                // two invites somebody to revoke something already gone.
                'sessions'      => app(SessionService::class)->listFor($staff, null),
            ],
        ]);
    }

    /**
     * End one session, or all of them.
     *
     * Separate from toggle-status on purpose: locking somebody out of the
     * company and signing a lost phone out are different decisions, and having
     * only the first means the second gets done with the first.
     */
    public function revokeSessions(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        $staff = $this->manageable($actor->tenant_id)->findOrFail($id);

        $count = app(SessionService::class)->forceLogout($staff, $actor);

        return response()->json([
            'status'  => 'success',
            'message' => $count === 1 ? 'One session ended.' : "{$count} sessions ended.",
            'data'    => ['revoked' => $count],
        ]);
    }

    /* ── Activity: what this person has done ─────────────────────────── */

    /**
     * The audit trail, both directions.
     *
     * What they did (actor_id) AND what was done to them (auditable), because
     * "who changed this person's permissions" is exactly as interesting as
     * "what did this person change", and looking in two places for one answer
     * is how the question stops being asked.
     */
    public function activity(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $staff    = $this->manageable($tenantId)->findOrFail($id);

        $rows = DB::table('audit_logs')
            ->where('tenant_id', $tenantId)
            ->where(function ($q) use ($staff) {
                $q->where('actor_id', $staff->id)
                    ->orWhere(function ($q2) use ($staff) {
                        $q2->where('auditable_type', User::class)->where('auditable_id', $staff->id);
                    });
            })
            ->orderByDesc('id')
            ->limit(100)
            ->get(['id', 'action', 'auditable_type', 'auditable_id', 'actor_id', 'actor_name', 'comment', 'created_at']);

        // Said from the reader's point of view rather than the row's: the same
        // entry means something different depending on which side you are on.
        $rows->each(function ($r) use ($staff) {
            $r->direction = (int) $r->actor_id === (int) $staff->id ? 'by_them' : 'to_them';
            $r->subject   = class_basename((string) $r->auditable_type);
        });

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    /* ── Notes: what colleagues need to know ─────────────────────────── */

    public function notes(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $this->manageable($tenantId)->findOrFail($id);

        // Through NoteService, not Note::create — it sanitises content on the way
        // in, and a second path into the same table is a second place for that to
        // be forgotten.
        return response()->json([
            'status' => 'success',
            'data'   => app(NoteService::class)->listForSubject(User::class, $id, $tenantId),
        ]);
    }

    public function addNote(Request $request, int $id): JsonResponse
    {
        $actor    = $request->user();
        $tenantId = $actor->tenant_id;
        $this->manageable($tenantId)->findOrFail($id);

        // `title` is required because notes.title is NOT NULL and `content` is
        // not — the shared table treats the title as the note's identity. Widening
        // that column for this one screen would change a contract five other
        // modules already keep.
        $data = $request->validate([
            'title'   => 'required|string|max:120',
            'content' => 'nullable|string|max:5000',
        ]);

        // The subject comes from the route, never the body, so a note cannot be
        // retargeted at another record by editing a payload.
        $note = app(NoteService::class)->createForSubject(User::class, $id, $data, $tenantId, $actor->id);

        return response()->json(['status' => 'success', 'message' => 'Note added.', 'data' => $note], 201);
    }

    public function deleteNote(Request $request, int $id, int $noteId): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $this->manageable($tenantId)->findOrFail($id);

        // Scoped through the SUBJECT as well as the tenant, so a note id taken
        // from another person's record does not resolve.
        //
        // No author check: this whole route group is behind role:admin, so every
        // caller here is already an admin. A guard nobody can trip is a guard
        // that only looks like protection.
        $note = Note::where('tenant_id', $tenantId)
            ->forSubject(User::class, $id)
            ->findOrFail($noteId);

        app(NoteService::class)->delete($note, $tenantId);

        return response()->json(['status' => 'success', 'message' => 'Note removed.']);
    }

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
    /**
     * The role list for the staff form.
     *
     * Backed by staff_roles now. It used to return a hardcoded array of seven
     * labels while the PERMISSION templates lived in a JavaScript map with eight
     * different keys — so 'junior_executive' pre-filled nothing and two templates
     * could not be reached at all. One list, from one place, is the fix.
     *
     * The shape is unchanged (value + label) so existing callers keep working;
     * `id` is added for anything that wants to assign the role properly.
     */
    public function designations(Request $request): JsonResponse
    {
        $roles = app(StaffRoleService::class)->forTenant((int) $request->user()->tenant_id);

        return response()->json([
            'status' => 'success',
            'data'   => $roles->map(fn ($r) => [
                'id'        => $r->id,
                'value'     => $r->slug,
                'label'     => $r->name,
                'is_system' => $r->is_system,
            ])->values(),
        ]);
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
