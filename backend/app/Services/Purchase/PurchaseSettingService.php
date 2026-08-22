<?php

namespace App\Services\Purchase;

use App\Models\Purchase\PurchaseSetting;
use App\Models\User;

/**
 * Purchase Settings — one tenant-scoped key/value store for the whole module.
 *
 * DEFAULTS below IS the contract: it declares every configurable key and the
 * value a tenant who has never touched Settings should see. Reading a key that
 * has no stored row returns its default, so the UI never has to guess what
 * "unset" means.
 *
 * Behaviour note (deliberate): document numbering only switches to the
 * configured prefix/next-number scheme once a row actually EXISTS for those
 * keys — i.e. after the tenant saves Settings. Until then each module keeps its
 * original format, so turning this screen on never silently renumbers an
 * existing tenant. See PurchaseNumbering.
 */
class PurchaseSettingService
{
    /** key => default. Grouped by the Settings tab that owns it. */
    public const DEFAULTS = [
        // ── General Settings · General Information ───────────────────────
        'pur_order_prefix'                  => '#PO',
        'pur_request_prefix'                => '#PR',
        'pur_inv_prefix'                    => '#INV',
        'debit_note_prefix'                 => 'DN-',
        'next_po_number'                    => 1,
        'next_pr_number'                    => 1,
        'pur_invoice_auto_operations_hour'  => 21,

        // ── General Settings · Shipping Information ──────────────────────
        'pur_company_address'      => '',
        'pur_company_city'         => '',
        'pur_company_state'        => '',
        'pur_company_country_text' => '',   // country code, e.g. IN
        'pur_company_zipcode'      => '',
        'pur_company_country_code' => '',   // country name

        // ── General Settings · Other Information ─────────────────────────
        'terms_and_conditions' => '',
        'vendor_note'          => '',

        // ── Purchase Options (toggles) ───────────────────────────────────
        'purchase_order_setting'                  => true,   // PO without PR/quotation
        'item_by_vendor'                          => false,  // load items by vendor
        'po_only_prefix_and_number'               => false,
        'allow_vendors_to_register'               => true,
        'show_purchase_tax_column'                => true,
        'send_email_welcome_for_new_contact'      => true,
        'reset_purchase_order_number_every_month' => false,

        // ── Order Return ─────────────────────────────────────────────────
        'pur_order_return_number_prefix' => 'OReturn',
        'next_pur_order_return_number'   => 1,

        // ── Temporary vendor access ──────────────────────────────────────
        // Days of portal access a Temporary Vendor gets, counted from the
        // moment an admin activates them (never from registration).
        'temporary_vendor_validity_days' => 5,

        // ── Governance behaviour (§34) ───────────────────────────────────
        // Per-tenant overrides for what used to be env/config-only. Consumers
        // read the stored value when a row EXISTS, else fall back to config —
        // so a tenant who never opens this tab keeps the deployment default.
        //
        // How the worker gate reacts to a worker holding no issued PPE:
        //   'warn' (default) admit + surface a warning · 'deny' refuse entry ·
        //   'off' skip the PPE check entirely.
        'gate_ppe_enforcement' => 'warn',
        // Push a message to the vendor the moment an NCR is raised or a violation
        // is recorded, on top of the pull-based alerts feed.
        'communications_auto_dispatch' => true,
    ];

    /** Keys whose values are booleans (so the API round-trips real bools). */
    private const BOOL_KEYS = [
        'purchase_order_setting', 'item_by_vendor', 'po_only_prefix_and_number',
        'allow_vendors_to_register', 'show_purchase_tax_column',
        'send_email_welcome_for_new_contact', 'reset_purchase_order_number_every_month',
        'communications_auto_dispatch',
    ];

    private const INT_KEYS = [
        'next_po_number', 'next_pr_number', 'pur_invoice_auto_operations_hour',
        'next_pur_order_return_number', 'temporary_vendor_validity_days',
    ];

    /** Every setting for a tenant: stored values layered over the defaults. */
    public function all(int $tenantId): array
    {
        $stored = PurchaseSetting::forTenant($tenantId)->pluck('value', 'key')->all();

        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $out[$key] = array_key_exists($key, $stored)
                ? $this->cast($key, $stored[$key])
                : $default;
        }

        return $out;
    }

    /** A single setting, falling back to its declared default. */
    public function get(int $tenantId, string $key)
    {
        $row = PurchaseSetting::forTenant($tenantId)->where('key', $key)->first();

        return $row ? $this->cast($key, $row->value) : (self::DEFAULTS[$key] ?? null);
    }

    /** Has the tenant explicitly configured this key? (drives numbering) */
    public function isConfigured(int $tenantId, string $key): bool
    {
        return PurchaseSetting::forTenant($tenantId)->where('key', $key)->exists();
    }

    /** Write one key. Unknown keys are ignored — DEFAULTS is the allowlist. */
    public function set(int $tenantId, string $key, $value, ?User $actor = null): void
    {
        if (! array_key_exists($key, self::DEFAULTS)) {
            return;
        }

        PurchaseSetting::updateOrCreate(
            ['tenant_id' => $tenantId, 'key' => $key],
            ['value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value, 'updated_by' => $actor?->id],
        );
    }

    /** Bulk write (the Settings forms). Returns the full, refreshed set. */
    public function update(int $tenantId, array $values, ?User $actor = null): array
    {
        foreach ($values as $key => $value) {
            $this->set($tenantId, $key, $value, $actor);
        }

        return $this->all($tenantId);
    }

    private function cast(string $key, $raw)
    {
        if (in_array($key, self::BOOL_KEYS, true)) {
            return (bool) (int) $raw;
        }
        if (in_array($key, self::INT_KEYS, true)) {
            return (int) $raw;
        }

        return (string) $raw;
    }
}
