<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SangoeTrack Integration
    |--------------------------------------------------------------------------
    |
    | Pulls attendance from the external SangoeTrack HRM (track.sangoe.in) into
    | this CRM's own hr_attendance table, so the existing Attendance / HR
    | Dashboard screens render it with no second module and no second source of
    | truth. Disabled unless credentials are present.
    |
    */

    'enabled' => env('SANGOETRACK_ENABLED', false),

    'base_url' => rtrim(env('SANGOETRACK_BASE_URL', 'https://track.sangoe.in/api'), '/'),

    'email'    => env('SANGOETRACK_EMAIL'),
    'password' => env('SANGOETRACK_PASSWORD'),

    /*
    | Default workspace used when an employee has no sangoetrack_workspace_id of
    | its own. SangoeTrack scopes attendance per workspace, so this must match
    | the workspace the credentials above belong to.
    */
    'workspace_id' => env('SANGOETRACK_WORKSPACE_ID'),

    'http' => [
        'timeout'      => (int) env('SANGOETRACK_TIMEOUT', 20),
        'retry_times'  => (int) env('SANGOETRACK_RETRY_TIMES', 2),
        'retry_sleep'  => (int) env('SANGOETRACK_RETRY_SLEEP', 400), // ms
    ],

    /*
    | The JWT is cached so a sync of N employees performs one login, not N.
    | Kept deliberately below a typical 60-minute JWT lifetime; a 401 mid-run
    | busts the cache and retries once regardless, so this is an optimisation
    | rather than a correctness guarantee.
    */
    'token_ttl' => (int) env('SANGOETRACK_TOKEN_TTL', 50 * 60),

    /*
    |--------------------------------------------------------------------------
    | Endpoint map
    |--------------------------------------------------------------------------
    |
    | Verified against the SangoeTrack route table, not guessed. Three of the
    | five original entries were wrong and would have 404'd on first use:
    |
    |   login        was '/login'.  Every route there lives under a {module}
    |                prefix, so '/api/login' matches nothing — it needs '/Hrm/'.
    |   leaves       was '/Hrm/leaves'      — the route is 'get-leaves'.
    |   leave_types  was '/Hrm/leave-types' — the route is 'get-leaves-types'.
    |
    | 'attendence-history' keeps their spelling on purpose. It is their route
    | name; correcting it here would 404.
    |
    | Every endpoint below is POST except 'admin_dashboard', which they declared
    | as GET — see SangoeTrackClient::GET_ENDPOINTS.
    |
    */
    'endpoints' => [
        // ── auth ────────────────────────────────────────────────────────────
        'login'              => '/Hrm/login',

        // ── employee-facing reads ───────────────────────────────────────────
        // attendance_history is scoped to the TOKEN HOLDER, not to whoever the
        // request names: their auth middleware overwrites user_id with the
        // authenticated user's id. It cannot be used to read another employee's
        // history — that needs an endpoint we add on their side.
        'attendance_history' => '/Hrm/attendence-history',
        'leaves'             => '/Hrm/get-leaves',
        'leave_types'        => '/Hrm/get-leaves-types',
        'leave_balance'      => '/Hrm/leave-balance',
        'holidays'           => '/Hrm/holidays-list',
        'advance_ledger'     => '/Hrm/advance/ledger',

        // ── admin reads ─────────────────────────────────────────────────────
        // attendance_details is TODAY ONLY — it accepts no date parameter.
        'admin_dashboard'          => '/Hrm/admin/dashboard',
        'admin_attendance_details' => '/Hrm/admin/attendance-details',
        'admin_pending_approvals'  => '/Hrm/admin/pending-approvals',
        'admin_pending_settlements' => '/Hrm/admin/pending-settlements',
        'admin_employees_list'     => '/Hrm/admin/employees-list',
        'admin_assignable_roles'   => '/Hrm/admin/assignable-roles',
        'admin_payroll_overview'   => '/Hrm/admin/payroll-overview',
        'admin_reports'            => '/Hrm/admin/reports',
        'admin_reports_summary'    => '/Hrm/admin/reports-summary',
        'admin_demo_requests'      => '/Hrm/admin/demo-requests',

        // ── admin writes ────────────────────────────────────────────────────
        // These go through their controllers on purpose: approving a correction
        // also writes the attendance row and pushes a notification to the
        // employee's phone. Writing to their tables directly would skip both.
        'admin_approve_leave'         => '/Hrm/admin/approve-reject-leave',
        'admin_approve_raise'         => '/Hrm/admin/approve-reject-raise',
        'admin_approve_reimbursement' => '/Hrm/admin/approve-reject-reimbursement',
        'admin_approve_advance'       => '/Hrm/admin/approve-reject-advance',
        'admin_disburse_advance'      => '/Hrm/admin/disburse-advance',
        'admin_review_settlement'     => '/Hrm/admin/review-settlement',
        'admin_set_salary'            => '/Hrm/admin/set-employee-salary',
        'admin_create_employee'       => '/Hrm/admin/create-employee',
        'admin_reset_password'        => '/Hrm/admin/reset-employee-password',
        'admin_update_demo_request'   => '/Hrm/admin/update-demo-request',

        // ── endpoints added on SangoeTrack for this CRM ─────────────────────
        // These live under /Hrm/crm/ in a route file of their own on their side,
        // so nothing the published mobile app calls was touched to add them.
        'crm_hrm_settings'           => '/Hrm/crm/hrm-settings',
        'crm_hrm_settings_save'      => '/Hrm/crm/hrm-settings/save',
        'crm_whatsapp_settings'      => '/Hrm/crm/whatsapp-settings',
        'crm_whatsapp_settings_save' => '/Hrm/crm/whatsapp-settings/save',

        // ── history ─────────────────────────────────────────────────────────
        // Read-only, added on their side for this CRM. The mobile API answers
        // "what is waiting on me" — pending only, today only — so these are the
        // only way to see what actually happened.
        //
        // All accept status / employee / from / to / page / per_page.
        // Advances also takes `type`; leaves also takes `leave_type`.
        'history_attendance'     => '/Hrm/crm/history/attendance',
        'history_corrections'    => '/Hrm/crm/history/corrections',
        'history_leaves'         => '/Hrm/crm/history/leaves',
        'history_reimbursements' => '/Hrm/crm/history/reimbursements',
        'history_advances'       => '/Hrm/crm/history/advances',
    ],

    /*
    |--------------------------------------------------------------------------
    | Employee roster — direct database read
    |--------------------------------------------------------------------------
    |
    | The roster comes from SangoeTrack's own MySQL database, not the HTTP API.
    | Attendance and leave still use the API; only the employee list is read
    | directly. READ ONLY — nothing here ever writes to that connection.
    |
    | `connection` is configurable so tests can point it at the test database
    | instead of a live MySQL host.
    |
    */
    'db' => [
        'connection' => env('SANGOETRACK_DB_CONNECTION', 'sangoetrack'),
        'table'      => 'employees',
        'workspace'  => (int) env('SANGOETRACK_WORKSPACE_ID', 1),

        /*
        | department_id / designation_id / branch_id are foreign keys in
        | SangoeTrack, but hr_employees stores plain strings. These lookups
        | resolve the label; a missing table is not fatal, the import falls back
        | to import_defaults instead.
        */
        'lookups' => [
            'department'  => ['table' => 'departments',  'key' => 'id', 'label' => 'name'],
            'designation' => ['table' => 'designations', 'key' => 'id', 'label' => 'name'],
            'branch'      => ['table' => 'branches',     'key' => 'id', 'label' => 'name'],
        ],
    ],

    /*
    | Which SangoeTrack column becomes hr_employees.sangoetrack_user_id.
    |
    | The employees table carries BOTH `id` (its own row id) and `user_id` (the
    | linked login). The attendance API takes a `user_id`, so that is the
    | default — but if attendance comes back empty for everyone after an import,
    | this is the first thing to flip to 'id'. No code change required.
    */
    'employee_key' => env('SANGOETRACK_EMPLOYEE_KEY', 'user_id'),

    /*
    |--------------------------------------------------------------------------
    | Response field mapping
    |--------------------------------------------------------------------------
    |
    | SangoeTrack's payload keys are not contractually guaranteed to us, so every
    | field the sync reads is listed here as an ordered list of candidates — the
    | first key present on a row wins. Run `php artisan sangoetrack:probe` to dump
    | a real response and confirm these before trusting a sync.
    |
    */
    'map' => [
        'token'      => ['token', 'access_token', 'jwt', 'data.token', 'data.access_token'],
        'rows'       => ['data', 'attendance', 'attendence', 'records', 'result'],
        'date'       => ['date', 'attendance_date', 'attendence_date', 'day'],
        'check_in'   => ['check_in', 'checkin', 'in_time', 'clock_in', 'punch_in'],
        'check_out'  => ['check_out', 'checkout', 'out_time', 'clock_out', 'punch_out'],
        'status'     => ['status', 'attendance_status', 'day_status'],

        // Employee/user listing (sangoetrack:import-employees). Email is the
        // match key against the CRM, so it is the one field with no fallback:
        // a row without it cannot be imported safely.
        'user_id'          => ['id', 'user_id', 'employee_id', 'user.id'],
        'user_name'        => ['name', 'full_name', 'employee_name', 'user.name', 'first_name'],
        'user_email'       => ['email', 'user_email', 'official_email', 'work_email', 'user.email'],
        'user_phone'       => ['phone', 'mobile', 'contact_number', 'phone_number', 'user.phone'],
        'user_department'  => ['department', 'department_name', 'dept', 'department.name'],
        'user_designation' => ['designation', 'designation_name', 'job_title', 'role', 'designation.name'],
        'user_code'        => ['employee_code', 'employee_id', 'emp_code', 'code'],
        'user_joining'     => ['joining_date', 'date_of_joining', 'doj', 'hire_date'],
    ],

    /*
    | Values written to hr_employees.source. Only IMPORTED is badged in the UI;
    | NULL (pre-existing rows) and MANUAL are treated the same.
    */
    'source' => [
        'imported' => 'sangoetrack',
        'manual'   => 'manual',
    ],

    /*
    | Defaults for columns SangoeTrack may not supply but hr_employees requires
    | NOT NULL. Kept here rather than hardcoded so an import can be tuned without
    | a code change.
    */
    'import_defaults' => [
        'department'   => env('SANGOETRACK_DEFAULT_DEPARTMENT', 'Unassigned'),
        'designation'  => env('SANGOETRACK_DEFAULT_DESIGNATION', 'Unassigned'),
        'status'       => 'Active',
        'joining_date' => null,   // falls back to today when the remote has none
    ],

    /*
    | Remote status string -> CRM HrAttendance::STATUSES. Anything unmatched is
    | left to the CRM's own derivation from check_in, which is the safer default.
    */
    'status_map' => [
        'present'   => 'Present',
        'p'         => 'Present',
        'absent'    => 'Absent',
        'a'         => 'Absent',
        'late'      => 'Late',
        'half day'  => 'Half Day',
        'half-day'  => 'Half Day',
        'halfday'   => 'Half Day',
        'hd'        => 'Half Day',
        'leave'     => 'Leave',
        'on leave'  => 'Leave',
        'l'         => 'Leave',
        'holiday'   => 'Holiday',
        'weekend'   => 'Weekend',
        'week off'  => 'Weekend',
        'wfh'       => 'Work From Home',
        'work from home' => 'Work From Home',
        'remote'    => 'Remote',
    ],
];
