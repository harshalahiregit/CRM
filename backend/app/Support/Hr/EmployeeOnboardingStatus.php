<?php

namespace App\Support\Hr;

/**
 * Employee Onboarding process status. Drives the dashboard buckets. String
 * values (never DB enums — SQLite alter trap), mirroring HiringRequestStatus.
 */
class EmployeeOnboardingStatus
{
    public const PENDING           = 'Pending';
    public const IN_PROGRESS       = 'In_Progress';
    public const WAITING_DOCUMENTS = 'Waiting_Documents';
    public const WAITING_HR        = 'Waiting_HR';
    public const WAITING_MANAGER   = 'Waiting_Manager';
    public const COMPLETED         = 'Completed';
    public const ON_HOLD           = 'On_Hold';

    public const ALL = [
        self::PENDING, self::IN_PROGRESS, self::WAITING_DOCUMENTS,
        self::WAITING_HR, self::WAITING_MANAGER, self::COMPLETED, self::ON_HOLD,
    ];

    public const LABELS = [
        self::PENDING           => 'Pending',
        self::IN_PROGRESS       => 'In Progress',
        self::WAITING_DOCUMENTS => 'Waiting for Documents',
        self::WAITING_HR        => 'Waiting for HR',
        self::WAITING_MANAGER   => 'Waiting for Manager',
        self::COMPLETED         => 'Completed',
        self::ON_HOLD           => 'On Hold',
    ];

    public static function label(?string $status): string
    {
        return self::LABELS[$status] ?? (string) $status;
    }
}
