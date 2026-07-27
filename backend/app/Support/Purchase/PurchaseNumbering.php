<?php

namespace App\Support\Purchase;

use App\Services\Purchase\PurchaseSettingService;

/**
 * Applies the Settings screen's prefix / next-number configuration to document
 * numbering — without ever renumbering a tenant behind their back.
 *
 * The rule: the configured scheme (`{prefix}{next}`) is used ONLY when the
 * tenant has explicitly stored BOTH keys, i.e. after they save Purchase
 * Settings. Until then `$default()` — each module's original format — is used
 * unchanged. That keeps existing installs byte-identical until someone opts in.
 *
 * Consuming a number bumps the stored next-number, so the sequence the user
 * typed into Settings is the sequence they get.
 */
final class PurchaseNumbering
{
    public static function next(int $tenantId, string $prefixKey, string $nextKey, callable $default): string
    {
        $settings = app(PurchaseSettingService::class);

        if (! $settings->isConfigured($tenantId, $prefixKey) || ! $settings->isConfigured($tenantId, $nextKey)) {
            return $default();
        }

        $prefix = trim((string) $settings->get($tenantId, $prefixKey));
        $seq    = (int) $settings->get($tenantId, $nextKey);

        if ($prefix === '' || $seq <= 0) {
            return $default();
        }

        $settings->set($tenantId, $nextKey, $seq + 1);

        return $prefix.$seq;
    }
}
