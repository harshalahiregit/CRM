<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\StoreEmployeeRequest;
use App\Http\Requests\Hr\UpdateEmployeeDetailRequest;
use App\Models\Hr\HrEmployee;
use App\Rules\Hr\ValidWorkState;
use App\Services\Hr\EmployeeDetailService;
use App\Services\Hr\EmployeeService;
use App\Support\Hr\WorkStates;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function __construct(
        private EmployeeService $employeeService,
        private EmployeeDetailService $details,
    )
    {
    }

    public function index(Request $request)
    {
        $page = $this->employeeService->list(
            $request->user()->tenant_id,
            $request->only(['status', 'department', 'designation', 'joined_from', 'search', 'per_page']),
            // Passing the actor is what lets a non-global role narrow this
            // list. A global role — which is every role today — is unchanged.
            $request->user(),
        );

        // Only for the people the block is FOR. It exists so an HR admin editing
        // an employee can see what their employment status does to their access;
        // to anybody else it is a list of which accounts exist, which of them are
        // admins, and which can sign in — reconnaissance, not a directory. The
        // employee directory itself stays readable by staff, as it was.
        if ($request->user()->canManageHrQueue()) {
            $page = $this->withLoginState($page, (int) $request->user()->tenant_id);
        }

        return response()->json($page);
    }

    /**
     * Attach each employee's login state to the row.
     *
     * The employee form owns this person's identity, and it was the one screen
     * that could not say whether they could actually get in — an admin could set
     * somebody Inactive and had no way to see what that did to their access. The
     * answer lives on `users` plus the employment gate, so it is resolved here
     * and shown rather than left for somebody to guess.
     *
     * Read-only, and deliberately small: the account email, whether it can sign
     * in, and why not when it cannot. Changing any of it is Staff Management's
     * job — this is a window, not a second editor.
     *
     * One query for the page, not one per row.
     */
    private function withLoginState($page, int $tenantId)
    {
        $userIds = collect($page->items())->pluck('user_id')->filter()->unique();

        $users = $userIds->isEmpty()
            ? collect()
            : \App\Models\User::where('tenant_id', $tenantId)
                ->whereIn('id', $userIds)
                ->get(['id', 'email', 'status', 'role'])
                ->keyBy('id');

        $signIn = \App\Services\Hr\EmployeeIdentityService::EMPLOYMENT_STATUSES_THAT_MAY_SIGN_IN;

        $page->setCollection($page->getCollection()->map(function ($employee) use ($users, $signIn) {
            $user = $employee->user_id ? $users->get($employee->user_id) : null;

            $employee->setAttribute('login', $user ? [
                'user_id'      => $user->id,
                'email'        => $user->email,
                'role'         => $user->role,
                'status'       => $user->status,
                'can_sign_in'  => $user->status === 'active'
                                  && in_array((string) $employee->status, $signIn, true),
                'blocked_because' => match (true) {
                    $user->status !== 'active' => 'The account is '.$user->status.'.',
                    ! in_array((string) $employee->status, $signIn, true)
                        => 'Employment is '.$employee->status.'.',
                    default => null,
                },
            ] : null);

            return $employee;
        }));

        return $page;
    }

    /**
     * Gated like update() twelve lines below, which it always should have been:
     * creating a person is at least as much authority as renaming one.
     */
    public function store(StoreEmployeeRequest $request)
    {
        $this->assertCanManage($request);

        $employee = $this->employeeService->create($request->validated(), $request->user()->tenant_id);

        return response()->json($employee, 201);
    }

    public function show(Request $request, HrEmployee $employee)
    {
        $this->assertTenant($request, $employee);

        return response()->json($employee);
    }

    /**
     * The extended record: personal, address, education, emergency contact,
     * bank, identity and statutory.
     *
     * Always returns every field, null where unset, so the form renders without
     * having to special-case a person who has none of it filled in yet.
     */
    /**
     * The extended personal record — and the most sensitive read in the module.
     *
     * It returns bank account number, IFSC, PAN, Aadhaar, UAN, ESIC, PF, date of
     * birth, personal email and home address. The WRITE beside it has been
     * HR-only since it was written; the read had nothing but a tenant check, so
     * any signed-in account could fetch any colleague's bank and identity numbers
     * by id. Confirmed with a token for a staff account holding no permission
     * role at all: it read a seeded Aadhaar and account number in full.
     *
     * Two callers are legitimate: somebody who administers HR, and the person
     * whose record it is. Everything else is refused — 403 rather than 404,
     * because unlike the list endpoints this is not about hiding that the
     * employee exists, it is about who may read their identity documents.
     */
    public function detail(Request $request, HrEmployee $employee)
    {
        $this->assertTenant($request, $employee);
        $this->assertCanReadDetail($request, $employee);

        return response()->json([
            'status' => 'success',
            'data'   => $this->details->get($employee),
        ]);
    }

    private function assertCanReadDetail(Request $request, HrEmployee $employee): void
    {
        if ($request->user()->canManageHrQueue()) {
            return;
        }

        // Your own record is yours to read. There is no self-service screen for
        // it today, but refusing somebody their own bank details would be the
        // wrong rule to write down for the one that arrives later.
        if ($employee->user_id && (int) $employee->user_id === (int) $request->user()->id) {
            return;
        }

        abort(403, 'You are not authorised to view this employee’s personal details');
    }

    /**
     * HR-only, and the gate goes BEFORE the write.
     *
     * This is the record holding bank account, IFSC, PAN and Aadhaar. It had the
     * tenant check but no authority check, so any signed-in staff member could
     * rewrite another person's payment and identity details — verified, and the
     * audit line then named them as the author. read (detail()) is left as it
     * was; only the write is gated, so nothing that merely displays the form
     * changes behaviour.
     *
     * Deliberately NOT an "or it's my own record" exemption: self-service edits
     * belong on a /me route with their own rules about which fields a person may
     * change, not on an endpoint that takes an arbitrary {employee}.
     */
    public function updateDetail(UpdateEmployeeDetailRequest $request, HrEmployee $employee)
    {
        $this->assertTenant($request, $employee);
        $this->assertCanManage($request);

        $this->details->save($employee, $request->validated(), $request->user());

        return response()->json([
            'status'  => 'success',
            'message' => 'Employee details saved',
            'data'    => $this->details->get($employee->fresh()),
        ]);
    }

    /** Full enterprise profile — recruitment + onboarding + offer + documents + timeline. */
    public function profile(Request $request, HrEmployee $employee)
    {
        $this->assertTenant($request, $employee);

        return response()->json($this->employeeService->profile($employee));
    }

    public function update(Request $request, HrEmployee $employee)
    {
        $this->assertTenant($request, $employee);
        $this->assertCanManage($request);

        $data = $request->validate([
            'name'                   => 'sometimes|required|string',
            'email'                  => 'nullable|email',
            'phone'                  => 'nullable|string',
            'dob'                    => 'nullable|date',
            'gender'                 => 'nullable|in:Male,Female,Other,Prefer not to say',
            'address'                => 'nullable|string',
            // See StoreEmployeeRequest: the master record, not a typed string,
            // and tenant-scoped so another workspace's department cannot be
            // assigned. `sometimes` because an edit that touches neither must
            // leave both exactly as they are.
            'department_id'          => [
                'sometimes', 'required', 'integer',
                \Illuminate\Validation\Rule::exists('hr_departments', 'id')
                    ->where('tenant_id', $request->user()->tenant_id),
            ],
            'designation_id'         => [
                'sometimes', 'required', 'integer',
                \Illuminate\Validation\Rule::exists('hr_designations', 'id')
                    ->where('tenant_id', $request->user()->tenant_id),
            ],
            // See StoreEmployeeRequest. Nullable so an override can be cleared
            // back to "none chosen".
            'employment_type_id'     => [
                'nullable', 'integer',
                \Illuminate\Validation\Rule::exists('hr_employment_types', 'id')
                    ->where('tenant_id', $request->user()->tenant_id),
            ],
            // Grade was a dimension nothing could set.
            //
            // hr_employees.grade_id is fillable and has a relation, the employee
            // profile renders a Grade field, Organization Setup lets you create
            // grades, and leave policies, exit policies and the salary report all
            // target one. But no form wrote it and these rules did not accept it,
            // so every employee's grade was permanently null — which quietly means
            // a leave or exit policy scoped to a grade can never match anybody.
            'grade_id'               => [
                'nullable', 'integer',
                \Illuminate\Validation\Rule::exists('hr_grades', 'id')
                    ->where('tenant_id', $request->user()->tenant_id),
            ],
            // See StoreEmployeeRequest for why both exist. The service rejects a
            // self-reference and a cycle; existence and tenant are checked here.
            'reporting_manager_id'   => [
                'nullable', 'integer',
                \Illuminate\Validation\Rule::exists('hr_employees', 'id')
                    ->where('tenant_id', $request->user()->tenant_id),
            ],
            'reporting_manager_name' => 'nullable|string',
            // See StoreEmployeeRequest. Null clears the override back to
            // inheriting; 0 is an explicit "no notice".
            'notice_days'            => 'nullable|integer|min:0|max:365',
            'work_state'             => ['nullable', 'string', 'max:80', new ValidWorkState],
            'joining_date'           => 'nullable|date',
            'probation_end_date'     => 'nullable|date',
            'confirmation_date'      => 'nullable|date',
            'status'                 => 'nullable|in:Active,On Leave,Inactive',
            // #29 — without these in the whitelist, validate() drops them and the
            // org-chart settings silently never save.
            'worker_type'            => 'nullable|in:employee,consultant,freelancer',
            'include_in_org_chart'   => 'nullable|boolean',
            // HR grants app access on the employee record; Staff Management owns
            // what somebody can do inside the CRM. Two different questions.
            'app_login_enabled'      => 'nullable|boolean',
        ]);

        $updated = $this->employeeService->update($employee, $data, $request->user());

        return response()->json($updated);
    }

    public function destroy(Request $request, HrEmployee $employee)
    {
        $this->assertTenant($request, $employee);
        $this->assertCanManage($request);

        $this->employeeService->destroy($employee);

        return response()->json(['message' => 'Deleted']);
    }

    public function stats(Request $request)
    {
        return response()->json($this->employeeService->stats($request->user()->tenant_id));
    }

    /**
     * The work-state vocabulary for the employee form's dropdown.
     *
     * Served from the backend so the list the UI offers and the list PT rules are
     * matched against can never drift apart.
     */
    public function workStates()
    {
        return response()->json(['data' => WorkStates::options()]);
    }

    /**
     * Two boundaries, both answered with 404, and both before anything is read.
     *
     * The tenant check is the older one. The scope check is the second half of
     * the data boundary the role's scope draws: narrowing the LIST while leaving
     * show/detail/update open means the boundary holds right up until somebody
     * types an id into the URL, which is the first thing anyone tries.
     *
     * 404 rather than 403 on purpose — "you may not see employee 41" confirms
     * that 41 exists and sits outside your department, which is the thing being
     * withheld. Out of scope should look like not there.
     *
     * A global actor is unaffected, which is every role on every existing
     * database.
     */
    private function assertTenant(Request $request, HrEmployee $employee): void
    {
        abort_unless((int) $employee->tenant_id === (int) $request->user()->tenant_id, 404, 'Employee not found');

        app(\App\Services\Auth\ScopeResolver::class)
            ->assertCanActOnEmployee($request->user(), $employee->id);
    }

    private function assertCanManage(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage employees');
    }
}
