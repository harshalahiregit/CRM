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
 *
 * The working-day defaults MUST therefore match HrAttendance::SHIFTS['General']
 * and STANDARD_HOURS exactly. They did not — 09:30/18:30 and a 9-hour day against
 * the constants' 09:00/18:00 and 8 — which was harmless only while nothing read
 * them. The moment attendance began honouring these, those numbers would have
 * moved every workspace's working day without anybody asking for it. Changing one
 * of these means changing the constant too; there is a test pinning the pair.
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
     * A list of options, one per line.
     *
     * Stored as newline-separated text rather than JSON so the generic settings
     * screen can render it as a textarea with no special case, and so somebody
     * editing it sees a list rather than punctuation.
     */
    public const TYPE_LIST   = 'list';

    /**
     * key => [label, type, default, hint, section]
     */
    public const DEFINITIONS = [
        /* ── the working day ─────────────────────────────────────────── */
        // 09:30-18:30 with a 15-minute grace window, per the HR meeting of
        // 2026-09-05. These are the AUTHORITY: HrAttendance::SHIFTS seeds a new
        // record when no setting is readable, and follows these rather than the
        // other way round. An earlier pass had this backwards and moved the
        // working day to 09:00-18:00 to match the constant.
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
            'Half day', self::TYPE_DECIMAL, 4,
            'Hours that count as half a day.', 'Working day',
        ],
        'clock_out_reminder_enabled' => [
            'Remind people to clock out', self::TYPE_BOOL, true,
            'Nudges anyone still clocked in after the hours below. The app already offers each person their own switch for this.', 'Working day',
        ],
        'clock_out_reminder_after_hours' => [
            'Remind after', self::TYPE_DECIMAL, 10,
            'Hours clocked in before the reminder goes out. One reminder per person per day.', 'Working day',
        ],

        /* ── late marks ──────────────────────────────────────────────── */
        //
        // The policy itself is numbers on this screen, not a rule in code, so HR
        // can change the thresholds or switch the whole thing off without a
        // release. Enforced by LateMarkDeductionService at process time; the
        // count and the money are frozen onto the payroll record, so an
        // attendance correction filed later cannot change what a past month
        // paid.
        'late_marks_enabled' => [
            'Deduct for repeated late marks', self::TYPE_BOOL, false,
            'When off, a late clock-in is recorded but never costs any pay.', 'Late marks',
        ],
        'late_marks_first_penalty_at' => [
            'Late marks before the first deduction', self::TYPE_INT, 3,
            'How many late marks in a month before half a day is deducted.', 'Late marks',
        ],
        'late_marks_first_penalty_days' => [
            'First deduction', self::TYPE_DECIMAL, 0.5,
            'Days of pay deducted when the count above is reached. 0.5 is half a day.', 'Late marks',
        ],
        'late_marks_second_penalty_at' => [
            'Late marks before the second deduction', self::TYPE_INT, 5,
            'How many late marks before a further deduction. 0 turns it off.', 'Late marks',
        ],
        'late_marks_second_penalty_days' => [
            'Second deduction', self::TYPE_DECIMAL, 0.5,
            'Days of pay deducted at the second threshold. 1 is a full day.', 'Late marks',
        ],
        'late_marks_reset_monthly' => [
            'Count late marks per month', self::TYPE_BOOL, true,
            'When on, the count starts again on the first of each month.', 'Late marks',
        ],

        /* ── overtime ────────────────────────────────────────────────── */
        //
        // Named as an allowance head on 5 Sep. Attendance has recorded
        // `overtime_hours` per day since it was built and payroll never read the
        // column, so the hours were visible on the attendance screen and worth
        // nothing on the payslip.
        //
        // OFF by default. Switching on a payment silently, for a workspace whose
        // people have been clocking overtime with no expectation of being paid
        // for it, creates a back-pay argument nobody planned for.
        'overtime_enabled' => [
            'Pay for overtime hours', self::TYPE_BOOL, false,
            'When off, overtime hours are recorded on attendance but never paid.', 'Overtime',
        ],
        'overtime_multiplier' => [
            'Overtime rate', self::TYPE_DECIMAL, 2,
            'Multiple of the normal hourly rate. Indian factory law sets twice the ordinary rate; 1 pays flat.', 'Overtime',
        ],
        'overtime_daily_cap_hours' => [
            'Most overtime paid in one day', self::TYPE_DECIMAL, 4,
            'Hours beyond this on a single day are recorded but not paid. 0 removes the cap.', 'Overtime',
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

        // Evidence on a punch made from the CRM in a browser. The phone app
        // always takes a selfie and coordinates; a laptop may have no camera at
        // all, so neither of these ever REFUSES a punch — a day's attendance is
        // not worth losing over a broken webcam. What they change is whether the
        // punch is recorded as verified: when evidence is missing, the reason is
        // stored against the record and the register shows it, so HR can ask
        // about the ones that matter instead of every punch looking identical.
        'web_punch_require_selfie' => [
            'Ask for a selfie on web clock-in', self::TYPE_BOOL, false,
            'The punch still goes through if there is no camera — it is marked unverified with the reason.', 'Attendance',
        ],
        'web_punch_require_location' => [
            'Ask for location on web clock-in', self::TYPE_BOOL, true,
            'Coordinates are recorded when the browser allows it. A refusal is recorded too, never silently blank.', 'Attendance',
        ],

        /* ── the advance ladder ──────────────────────────────────────── */
        // These are why "more control" matters: the tiers were fixed in code.
        // The app's advance form had these baked into it, so changing what a
        // person may request meant rebuilding the app and getting everybody to
        // update. One per line; the part before a pipe is stored, the part after
        // is shown.
        'advance_types' => [
            'Advance types', self::TYPE_LIST,
            "salary|Salary Advance\nsite_cash|Site Cash Advance\nfuel|Fuel Advance\nmaterial|Material Purchase\ntravel|Travel Advance\nemergency_loan|Emergency Loan\nvendor_payment|Vendor Payment\npetty_cash|Petty Cash\nimprest|Temporary Imprest\nother|Other",
            'One per line, as value|Label. The app reads this, so a change reaches every phone without an update.', 'Advances',
        ],
        'advance_categories' => [
            'Advance categories', self::TYPE_LIST,
            "site_operations|Site Operations\nhr_admin|HR / Admin\ncorporate|Corporate\nother|Other",
            'One per line, as value|Label.', 'Advances',
        ],
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

        /* ── approvals ───────────────────────────────────────────────── */
        //
        // Whether the person who raised a request may also decide it.
        //
        // OFF BY DEFAULT, and that is not a recommendation — it is what the
        // system does today. Turning it on for existing workspaces would
        // change who can approve what without anybody asking, and in a company
        // with one HR person it would stop approvals entirely. So the default
        // preserves current behaviour and each workspace opts in.
        //
        // Advances are unaffected: their ladder does not run through the
        // engine's step gate and has carried its own
        // advance_require_distinct_approvers for longer.
        'require_distinct_approver' => [
            'Approvers must be someone other than the requester', self::TYPE_BOOL, false,
            'On, the person who raised a request cannot approve it — including when they are the reporting manager, hold the approving role, or manage the HR queue. Leave it off if too few people would be left to approve.', 'Approvals',
        ],

        /* ── POSH ────────────────────────────────────────────────────── */
        //
        // How long a complainant's portal link stays usable. It is a setting
        // rather than a constant because the right answer depends on how a
        // workspace runs its committee, and nothing in the statute that we
        // have verified fixes a number.
        //
        // The value is read ONLY at issuance and frozen onto the token, so
        // editing this moves nothing that has already been handed out.
        //
        // The generic integer rule below admits 0 and null. PoshTokenService
        // refuses both rather than treating them as "no expiry" — see the
        // fail-closed note there.
        'posh_token_ttl_days' => [
            'POSH complainant link validity', self::TYPE_INT, 30,
            'Days a complainant\'s portal link stays usable. Applies to links issued from now on; existing links keep the validity they were given.', 'POSH',
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
            // Normalised on the way in: blank lines and stray spaces are how a
            // list ends up with an empty option in the middle of a dropdown.
            self::TYPE_LIST => implode("\n", array_values(array_filter(
                array_map('trim', preg_split('/\r\n|\r|\n/', (string) $value) ?: []),
                fn ($line) => $line !== '',
            ))),
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
