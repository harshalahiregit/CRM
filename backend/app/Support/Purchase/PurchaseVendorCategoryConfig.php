<?php

namespace App\Support\Purchase;

/**
 * Purchase-owned onboarding configuration keyed off the vendor's category.
 *
 * Category is stored as free text on purchase_vendors, so resolution is keyword-
 * based (case-insensitive) rather than an exact enum match — this keeps working
 * regardless of tenant-defined category names. The two things every category
 * resolves to are `requires_workforce` and an ordered `onboarding_steps` list,
 * which the portal dashboard + onboarding APIs return so the React portal renders
 * the correct flow WITHOUT hardcoding any of this on the frontend.
 *
 * Purchase-owned: no TPV / shared Vendor coupling.
 */
final class PurchaseVendorCategoryConfig
{
    /** Categories whose name contains any of these need the Workforce step. */
    public const WORKFORCE_KEYWORDS = [
        'security', 'housekeeping', 'facility', 'facilities', 'labour', 'labor',
        'loading', 'unloading', 'driver', 'manpower', 'logistics', 'staffing',
        'workforce', 'cleaning', 'guard', 'contractor',
    ];

    /** Workforce vendors — Company → Documents → Workforce → Approvals → Kickoff → Activation. */
    public const WORKFORCE_STEPS = [
        ['key' => 'profile',    'label' => 'Company Profile'],
        ['key' => 'documents',  'label' => 'Documents'],
        ['key' => 'workforce',  'label' => 'Workforce'],
        ['key' => 'approval',   'label' => 'Approvals'],
        ['key' => 'kickoff',    'label' => 'Kickoff Meeting'],
        ['key' => 'activation', 'label' => 'Activation'],
    ];

    /** Service vendors — Company → Documents → Commercial → Approval → Kickoff → Activation. */
    public const SERVICE_STEPS = [
        ['key' => 'profile',    'label' => 'Company Profile'],
        ['key' => 'documents',  'label' => 'Documents'],
        ['key' => 'commercial', 'label' => 'Commercial Information'],
        ['key' => 'approval',   'label' => 'Approval'],
        ['key' => 'kickoff',    'label' => 'Kickoff Meeting'],
        ['key' => 'activation', 'label' => 'Activation'],
    ];

    /** Does this (free-text) category require the Workforce step? */
    public static function requiresWorkforce(?string $category): bool
    {
        $lc = strtolower(trim((string) $category));
        if ($lc === '') {
            return false;
        }
        foreach (self::WORKFORCE_KEYWORDS as $kw) {
            if (str_contains($lc, $kw)) {
                return true;
            }
        }

        return false;
    }

    /** The ordered onboarding step definition for a category. */
    public static function onboardingSteps(?string $category): array
    {
        return self::requiresWorkforce($category) ? self::WORKFORCE_STEPS : self::SERVICE_STEPS;
    }

    /** Full resolved config the APIs return. */
    public static function resolve(?string $category): array
    {
        $requires = self::requiresWorkforce($category);

        return [
            'category'           => trim((string) $category) ?: null,
            'requires_workforce' => $requires,
            'onboarding_steps'   => $requires ? self::WORKFORCE_STEPS : self::SERVICE_STEPS,
        ];
    }
}
