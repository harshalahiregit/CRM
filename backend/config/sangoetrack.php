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

    'endpoints' => [
        'login'              => '/login',
        'attendance_history' => '/Hrm/attendence-history',
        'leaves'             => '/Hrm/leaves',
        'leave_types'        => '/Hrm/leave-types',
        'leave_balance'      => '/Hrm/leave-balance',
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
