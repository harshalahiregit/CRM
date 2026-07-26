<?php

namespace App\Support\Hr;

/**
 * The 14-step Employee Onboarding lifecycle — the exact progress tracker:
 *
 *   Offer Accepted → Employee Created → Personal Information → Education →
 *   Employment History → Documents Verification → Compliance Verification →
 *   Bank & Payroll → IT & Asset Allocation → Orientation → Training →
 *   HR Approval → Manager Approval → Completed
 *
 * ORDER is the source of truth for both progress_percent and the frontend
 * horizontal tracker.
 */
class EmployeeOnboardingStage
{
    public const OFFER_ACCEPTED          = 'Offer_Accepted';
    public const EMPLOYEE_CREATED        = 'Employee_Created';
    public const PERSONAL_INFORMATION    = 'Personal_Information';
    public const EDUCATION               = 'Education';
    public const EMPLOYMENT_HISTORY      = 'Employment_History';
    public const DOCUMENTS_VERIFICATION  = 'Documents_Verification';
    public const COMPLIANCE_VERIFICATION = 'Compliance_Verification';
    public const BANK_PAYROLL            = 'Bank_Payroll';
    public const IT_ASSET_ALLOCATION     = 'IT_Asset_Allocation';
    public const ORIENTATION             = 'Orientation';
    public const TRAINING                = 'Training';
    public const HR_APPROVAL             = 'HR_Approval';
    public const MANAGER_APPROVAL        = 'Manager_Approval';
    public const COMPLETED               = 'Completed';

    /** Ordered lifecycle — index drives progress. */
    public const ORDER = [
        self::OFFER_ACCEPTED,
        self::EMPLOYEE_CREATED,
        self::PERSONAL_INFORMATION,
        self::EDUCATION,
        self::EMPLOYMENT_HISTORY,
        self::DOCUMENTS_VERIFICATION,
        self::COMPLIANCE_VERIFICATION,
        self::BANK_PAYROLL,
        self::IT_ASSET_ALLOCATION,
        self::ORIENTATION,
        self::TRAINING,
        self::HR_APPROVAL,
        self::MANAGER_APPROVAL,
        self::COMPLETED,
    ];

    public const LABELS = [
        self::OFFER_ACCEPTED          => 'Offer Accepted',
        self::EMPLOYEE_CREATED        => 'Employee Created',
        self::PERSONAL_INFORMATION    => 'Personal Information',
        self::EDUCATION               => 'Education',
        self::EMPLOYMENT_HISTORY      => 'Employment History',
        self::DOCUMENTS_VERIFICATION  => 'Documents Verification',
        self::COMPLIANCE_VERIFICATION => 'Compliance Verification',
        self::BANK_PAYROLL            => 'Bank & Payroll',
        self::IT_ASSET_ALLOCATION     => 'IT & Asset Allocation',
        self::ORIENTATION             => 'Orientation',
        self::TRAINING                => 'Training',
        self::HR_APPROVAL             => 'HR Approval',
        self::MANAGER_APPROVAL        => 'Manager Approval',
        self::COMPLETED               => 'Completed',
    ];

    public static function label(?string $stage): string
    {
        return self::LABELS[$stage] ?? (string) $stage;
    }

    public static function index(?string $stage): int
    {
        $i = array_search($stage, self::ORDER, true);

        return $i === false ? 0 : $i;
    }

    /** Percentage complete for a given current stage (0–100). */
    public static function progress(?string $stage): int
    {
        $last = count(self::ORDER) - 1;

        return (int) round((self::index($stage) / $last) * 100);
    }

    /** Ordered [value => label] for the frontend tracker. */
    public static function steps(): array
    {
        return array_map(fn ($s) => ['key' => $s, 'label' => self::LABELS[$s]], self::ORDER);
    }
}
