<?php

namespace App\Support\Hr;

/**
 * The HR settings a workspace can change, and what each one means.
 *
 * Stored in tenant_settings under the 'hr' group, so there is no new table and
 * the existing SettingsService cache applies. Declared here rather than left as
 * loose strings because a setting nobody can enumerate is a setting nobody can
 * put on a screen — which is how SangoeTrack's ended up editable only in their
 * admin.
 *
 * Every entry carries a TYPE and a DEFAULT. The default is what the system does
 * today, so turning settings on changes nothing until somebody edits one — a
 * settings screen that silently alters behaviour on first save is a bad trade.
 */
class HrSetting
{
    public const GROUP = 'hr';

    public const TYPE_TIME   = 'time';
    public const TYPE_INT    = 'int';
    public const TYPE_DECIMAL = 'decimal';
    public const TYPE_BOOL   = 'bool';
    public const TYPE_STRING = 'string';
    public const TYPE_EMAIL  = 'email';

    /**
     * key => [label, type, default, hint, section]
     */
    public const DEFINITIONS = [
        /* ── the working day ─────────────────────────────────────────── */
        'company_start_time' => [
            'Working day starts', self::TYPE_TIME, '09:30',
            'Used to decide whether a clock-in counts as late.', 'Working day',
        ],
        'company_end_time' => [
            'Working day ends', self::TYPE_TIME, '18:30',
            'The end of a standard shift.', 'Working day',
        ],
        'late_grace_minutes' => [
            'Grace period', self::TYPE_INT, 15,
            'Minutes after the start time before a clock-in is marked Late.', 'Working day',
        ],
        'max_shift_hours' => [
            'Longest shift', self::TYPE_DECIMAL, 12,
            'A clock-out beyond this is flagged rather than silently accepted.', 'Working day',
        ],
        'standard_day_hours' => [
            'Full day', self::TYPE_DECIMAL, 9,
            'Hours in a full working day. Anything beyond it counts as overtime.', 'Working day',
        ],
        'half_day_hours' => [
            'Half day', self::TYPE_DECIMAL, 4.5,
            'Hours that count as half a day.', 'Working day',
        ],

        /* ── attendance ──────────────────────────────────────────────── */
        'ip_restrict' => [
            'Restrict clock-in to approved addresses', self::TYPE_BOOL, false,
            'When on, people can only clock in from the addresses listed below.', 'Attendance',
        ],
        'allowed_ips' => [
            'Approved addresses', self::TYPE_STRING, '',
            'Comma-separated. Only used when the restriction above is on.', 'Attendance',
        ],
        'allow_self_correction' => [
            'Let employees ask for corrections', self::TYPE_BOOL, true,
            'Turning this off hides the correction request screen.', 'Attendance',
        ],
        'correction_window_days' => [
            'Correction window', self::TYPE_INT, 30,
            'How many days back somebody may ask to correct. 0 means no limit.', 'Attendance',
        ],

        /* ── the advance ladder ──────────────────────────────────────── */
        // These are why "more control" matters: the tiers were fixed in code.
        'advance_manager_limit' => [
            'Manager can approve up to', self::TYPE_DECIMAL, 0,
            'An advance at or below this needs only the manager. 0 means every advance goes the whole way.', 'Advances',
        ],
        'advance_accounts_limit' => [
            'Accounts can approve up to', self::TYPE_DECIMAL, 0,
            'At or below this, a director is not required. 0 means every advance needs one.', 'Advances',
        ],
        'advance_require_distinct_approvers' => [
            'Require a different person at each stage', self::TYPE_BOOL, true,
            'Off lets one person move an advance through several stages — quicker, and much weaker.', 'Advances',
        ],

        /* ── leave ───────────────────────────────────────────────────── */
        'leave_paid_days' => [
            'Paid leave a year', self::TYPE_DECIMAL, 12,
            'Used when setting up a new employee.', 'Leave',
        ],
        'leave_casual_days' => [
            'Casual leave a year', self::TYPE_DECIMAL, 12, '', 'Leave',
        ],
        'leave_unpaid_days' => [
            'Unpaid leave a year', self::TYPE_DECIMAL, 0, '', 'Leave',
        ],
        'leave_comp_off_days' => [
            'Comp-off a year', self::TYPE_DECIMAL, 0, '', 'Leave',
        ],

        /* ── people ──────────────────────────────────────────────────── */
        'employee_prefix' => [
            'Employee code prefix', self::TYPE_STRING, 'SNE-',
            'Changing this affects new employees only; existing codes are never rewritten.', 'People',
        ],
        'hr_notification_email' => [
            'HR notification address', self::TYPE_EMAIL, '',
            'Where requests needing HR are sent.', 'People',
        ],
        'app_login_default' => [
            'New employees can use the attendance app', self::TYPE_BOOL, false,
            'Off by default: app access is granted, never assumed.', 'People',
        ],
    ];

    public static function keys(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public static function isKey(string $key): bool
    {
        return isset(self::DEFINITIONS[$key]);
    }

    public static function defaults(): array
    {
        return array_map(fn ($d) => $d[2], self::DEFINITIONS);
    }

    public static function typeOf(string $key): ?string
    {
        return self::DEFINITIONS[$key][1] ?? null;
    }

    /**
     * Coerce a stored or submitted value to its declared type.
     *
     * Everything comes back from tenant_settings as a string, and a boolean read
     * as the string "false" is true — which is the kind of bug that turns a
     * safety setting on when somebody turned it off.
     */
    public static function cast(string $key, $value)
    {
        return match (self::typeOf($key)) {
            self::TYPE_BOOL => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            self::TYPE_INT  => (int) $value,
            self::TYPE_DECIMAL => (float) $value,
            default => $value === null ? '' : (string) $value,
        };
    }

    /**
     * The same definitions in the shape SettingRegistry wants.
     *
     * Generated rather than written out a second time: SettingsService::set()
     * SILENTLY does nothing for a key the registry does not know, so a list that
     * drifted would show a settings screen whose saves quietly went nowhere.
     */
    public static function registryGroup(): array
    {
        $out = [];

        foreach (self::DEFINITIONS as $key => [, $type, $default, ,]) {
            $out[$key] = [
                'cast'    => match ($type) {
                    self::TYPE_BOOL    => 'bool',
                    self::TYPE_INT     => 'int',
                    self::TYPE_DECIMAL => 'float',
                    default            => 'string',
                },
                'default' => $default,
                'rules'   => match ($type) {
                    self::TYPE_BOOL    => ['nullable', 'boolean'],
                    self::TYPE_INT     => ['nullable', 'integer', 'min:0'],
                    self::TYPE_DECIMAL => ['nullable', 'numeric', 'min:0'],
                    self::TYPE_TIME    => ['nullable', 'date_format:H:i'],
                    self::TYPE_EMAIL   => ['nullable', 'email', 'max:191'],
                    default            => ['nullable', 'string', 'max:500'],
                },
            ];
        }

        return $out;
    }

    /** The screen's shape: sections, in declaration order, each with its fields. */
    public static function schema(): array
    {
        $out = [];

        foreach (self::DEFINITIONS as $key => [$label, $type, $default, $hint, $section]) {
            $out[$section][] = compact('key', 'label', 'type', 'default', 'hint');
        }

        return $out;
    }
}
