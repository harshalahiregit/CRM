<?php

namespace App\Support\Shared;

/**
 * The compliance agencies a vendor can be handed off to.
 *
 * Mirrors COMPLIANCE_PROVIDERS in frontend/src/components/vendor/documentCatalog.js
 * — ids must match, because the settings key for an agency's lead address is
 * derived from its id. The frontend owns the look (badge, logo colour); this
 * owns who is real and where their leads go.
 *
 * Guarded by ProviderCallbackReachesTheProviderTest.
 */
final class ComplianceProviders
{
    /** id => display name. */
    public const ALL = [
        'business_badhega' => 'BusinessBadhega.com',
        'legaldesk'        => 'LegalDesk',
        'vakilsearch'      => 'VakilSearch',
    ];

    public static function exists(string $id): bool
    {
        return array_key_exists($id, self::ALL);
    }

    public static function name(string $id): ?string
    {
        return self::ALL[$id] ?? null;
    }

    /** The settings key holding this agency's lead address. */
    public static function emailKey(string $id): string
    {
        return $id.'_email';
    }
}
