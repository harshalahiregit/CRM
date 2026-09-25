<?php

namespace App\Support\Hr\Approval;

/**
 * How a step names the person it is waiting on.
 *
 * A closed set, three entries. StaffPermission.php already drew the line this
 * follows: a rung whose MEMBERSHIP is static can be named by a role, and one
 * resolved per record from the reporting line "no permission can express". Both
 * shapes are needed, and nothing here invents a third.
 *
 * Note what is absent. There is no `permission` type in v1 — the default
 * compatibility workflow needs one, and it is handled by the registry as a
 * synthetic step rather than a configurable option, because offering
 * "module:capability" in the Settings dropdown would ask an administrator to
 * understand the permission grid to configure an approval.
 */
final class ApproverType
{
    /**
     * The employee's own reporting manager, walked `levels_up` times.
     *
     * Resolved per request against hr_employees.reporting_manager_id, so the
     * answer changes when somebody's manager changes — which is the point.
     */
    public const REPORTING_MANAGER = 'reporting_manager';

    /** Anybody holding a given staff_roles row. Static membership. */
    public const STAFF_ROLE = 'staff_role';

    /** One named user. The escape hatch for "Priya signs these off". */
    public const SPECIFIC_USER = 'specific_user';

    /**
     * Internal only: the compatibility step the registry synthesises when a
     * tenant has configured no workflow. Never offered in the UI, never stored.
     * Matches exactly who canManageHrQueue() admits today.
     */
    public const LEGACY_HR_QUEUE = 'legacy_hr_queue';

    /** The types an administrator may actually choose. */
    public const CONFIGURABLE = [
        self::REPORTING_MANAGER,
        self::STAFF_ROLE,
        self::SPECIFIC_USER,
    ];

    public const ALL = [
        self::REPORTING_MANAGER,
        self::STAFF_ROLE,
        self::SPECIFIC_USER,
        self::LEGACY_HR_QUEUE,
    ];

    public static function isConfigurable(?string $type): bool
    {
        return in_array($type, self::CONFIGURABLE, true);
    }

    public static function label(string $type): string
    {
        return match ($type) {
            self::REPORTING_MANAGER => 'Reporting manager',
            self::STAFF_ROLE        => 'Staff role',
            self::SPECIFIC_USER     => 'Specific user',
            self::LEGACY_HR_QUEUE   => 'HR queue (default)',
            default                 => $type,
        };
    }
}
