<?php

namespace App\Services\Inventory;

use App\Exceptions\BusinessException;
use App\Models\Inventory\Config;
use App\Models\Inventory\CustomField;
use App\Models\Inventory\Movement;
use App\Models\Inventory\Product;
use App\Models\Inventory\Stock;
use App\Models\Inventory\Voucher;
use App\Models\Inventory\VoucherItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Settings tabs that are configuration rather than lookup lists
 * (blueprint §9): inventory rules, approval rules, min/max defaults, the
 * sale-price rule and the delivery-voucher costing method — plus custom field
 * definitions and the "Reset data" tool.
 *
 * Every key has a declared default here, so reading a setting a tenant has
 * never touched returns a sane value instead of null, and the UI never has to
 * guess what "unset" means.
 */
class ConfigService
{
    /** key => default value. This list IS the contract for what's configurable. */
    public const DEFAULTS = [
        // "Minimum/maximum inventory" tab — defaults applied to new items.
        'default_min_stock'        => 0,
        'default_max_stock'        => null,
        'default_reorder_point'    => 0,
        'low_stock_alerts'         => true,

        // "Rule sale price, Inventory delivery voucher method" tab.
        'sale_price_rule'          => 'profit_ratio',   // profit_ratio | fixed | manual
        'default_profit_ratio'     => 20,
        'delivery_costing_method'  => 'fifo',           // fifo | lifo | average

        // "Inventory setting" tab.
        'allow_negative_stock'     => false,
        'auto_generate_sku'        => true,
        'auto_generate_barcode'    => true,
        'expiry_alert_days'        => 30,
        'default_warehouse_id'     => null,

        // "Approval setting" tab.
        'require_approval_receipt'   => false,
        'require_approval_delivery'  => false,
        'require_approval_adjustment' => false,
        'approval_threshold_amount'  => 0,

        // "Notifications" tab. Each category is a separate switch, because the
        // people who want to hear about every posted receipt are rarely the same
        // people who want to hear about an expiring batch. `email_alerts` is the
        // master outbound switch — turning it off keeps the in-app bell working,
        // which is the right default for a workspace that lives in the app.
        'notify_stock_alerts'      => true,   // low stock / out of stock
        'notify_voucher_activity'  => true,   // posted, cancelled, incoming transfer
        'notify_expiry_alerts'     => true,   // batches nearing expiry
        'email_alerts'             => true,   // send the above as email too
        'alert_email_extra'        => null,   // extra address to copy (purchasing@…)
    ];

    /* ── Config ─────────────────────────────────────────────────── */

    /** Every setting for a tenant, defaults filled in for anything unset. */
    public function all(int $tenantId): array
    {
        $saved = Config::forTenant($tenantId)->pluck('value', 'key')->all();

        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            // Values are stored JSON-encoded; a scalar comes back wrapped in an
            // array by the model cast, so unwrap single-value bags here.
            $out[$key] = array_key_exists($key, $saved) ? $this->unwrap($saved[$key]) : $default;
        }

        return $out;
    }

    public function get(int $tenantId, string $key)
    {
        return $this->all($tenantId)[$key] ?? null;
    }

    /** Save a batch of settings. Unknown keys are refused, not silently dropped. */
    public function save(int $tenantId, array $values): array
    {
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, self::DEFAULTS)) {
                throw new BusinessException("Unknown inventory setting: {$key}", 422);
            }

            // The one free-text setting that gets posted to the outside world —
            // a typo here means alerts silently bounce, so refuse it up front.
            if ($key === 'alert_email_extra') {
                $value = trim((string) $value) ?: null;
                if ($value !== null && ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    throw new BusinessException('That alert email address is not valid.', 422);
                }
            }

            Config::updateOrCreate(
                ['tenant_id' => $tenantId, 'key' => $key],
                ['value' => ['v' => $value]],
            );
        }

        return $this->all($tenantId);
    }

    private function unwrap($stored)
    {
        return is_array($stored) && array_key_exists('v', $stored) ? $stored['v'] : $stored;
    }

    /* ── Custom fields ──────────────────────────────────────────── */

    public function customFields(int $tenantId, ?string $entity = null)
    {
        $q = CustomField::forTenant($tenantId)->orderBy('order')->orderBy('id');

        if ($entity) {
            $this->assertEntity($entity);
            $q->where('entity', $entity);
        }

        return $q->get();
    }

    public function createCustomField(int $tenantId, array $data): CustomField
    {
        $entity = $data['entity'] ?? 'product';
        $this->assertEntity($entity);
        $this->assertType($data['type'] ?? 'text');

        // The key is what the value bag is keyed by — derive it from the label so
        // the user never has to think about it, and keep it unique per entity.
        $key = Str::slug($data['label'], '_');
        if ($key === '') {
            throw new BusinessException('That label cannot be used as a field name.', 422);
        }

        $base = $key;
        $i = 2;
        while (CustomField::forTenant($tenantId)->where('entity', $entity)->where('key', $key)->exists()) {
            $key = "{$base}_{$i}";
            $i++;
        }

        return CustomField::create([
            'tenant_id' => $tenantId,
            'entity'    => $entity,
            'key'       => $key,
            'label'     => $data['label'],
            'type'      => $data['type'] ?? 'text',
            'options'   => $data['options'] ?? null,
            'required'  => (bool) ($data['required'] ?? false),
            'order'     => (int) ($data['order'] ?? 0),
        ]);
    }

    public function updateCustomField(int $tenantId, int $id, array $data): CustomField
    {
        $row = CustomField::forTenant($tenantId)->findOrFail($id);

        if (isset($data['type'])) {
            $this->assertType($data['type']);
        }

        // `key` is what existing values are stored under — renaming it would
        // orphan every value already saved, so it is deliberately immutable.
        unset($data['key'], $data['entity']);
        $row->update($data);

        return $row->fresh();
    }

    public function deleteCustomField(int $tenantId, int $id): void
    {
        CustomField::forTenant($tenantId)->findOrFail($id)->delete();
    }

    /**
     * Keep only values that match a defined field, and coerce each to its type.
     * Called on product/warehouse save so the JSON bag can never accumulate
     * junk keys or a checkbox holding the string "false".
     */
    public function sanitizeValues(int $tenantId, string $entity, ?array $values): ?array
    {
        if (empty($values)) {
            return null;
        }

        $defs = $this->customFields($tenantId, $entity)->keyBy('key');
        $out = [];

        foreach ($values as $key => $value) {
            $def = $defs[$key] ?? null;
            if (! $def || $value === null || $value === '') {
                continue;
            }

            $out[$key] = match ($def->type) {
                'number'   => is_numeric($value) ? (float) $value : null,
                'checkbox' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'select'   => in_array($value, $def->options ?? [], true) ? $value : null,
                default    => is_scalar($value) ? (string) $value : null,
            };

            if ($out[$key] === null) {
                unset($out[$key]);
            }
        }

        return $out ?: null;
    }

    private function assertEntity(string $entity): void
    {
        if (! in_array($entity, CustomField::ENTITIES, true)) {
            throw new BusinessException('Custom fields can only be attached to products or warehouses.', 422);
        }
    }

    private function assertType(string $type): void
    {
        if (! in_array($type, CustomField::TYPES, true)) {
            throw new BusinessException('Unsupported custom field type.', 422);
        }
    }

    /* ── Reset data (§9, last tab) ──────────────────────────────── */

    /**
     * Wipe inventory data for a tenant. Deliberately granular and deliberately
     * explicit: nothing is removed unless it was named, because this is the one
     * button in the module that destroys history.
     *
     * 'movements' clears the ledger AND zeroes balances — a ledger and a balance
     * that disagree would be worse than either being gone.
     */
    public function resetData(int $tenantId, array $scopes): array
    {
        $allowed = ['movements', 'vouchers', 'products', 'custom_fields', 'config'];
        $scopes = array_values(array_intersect($scopes, $allowed));

        if (! $scopes) {
            throw new BusinessException('Choose at least one thing to reset.', 422);
        }

        $deleted = [];

        DB::transaction(function () use ($scopes, $tenantId, &$deleted) {
            if (in_array('vouchers', $scopes, true) || in_array('products', $scopes, true)) {
                $voucherIds = Voucher::forTenant($tenantId)->pluck('id');
                $deleted['voucher_items'] = VoucherItem::forTenant($tenantId)->whereIn('voucher_id', $voucherIds)->forceDelete();
                $deleted['vouchers'] = Voucher::forTenant($tenantId)->forceDelete();
            }

            if (in_array('movements', $scopes, true) || in_array('products', $scopes, true)) {
                $deleted['movements'] = Movement::forTenant($tenantId)->delete();
                $deleted['stock'] = Stock::forTenant($tenantId)->delete();
                // Reservations hold against a balance that no longer exists, so
                // they go with it — leaving them would keep "reserved" pointing
                // at stock the ledger has forgotten.
                $deleted['reservations'] = \App\Models\Inventory\Reservation::forTenant($tenantId)->delete();
            }

            if (in_array('products', $scopes, true)) {
                // Batches and serials belong to a product; deleting the product
                // without them would orphan rows that reference nothing.
                $deleted['batches'] = \App\Models\Inventory\Batch::forTenant($tenantId)->delete();
                $deleted['serials'] = \App\Models\Inventory\Serial::forTenant($tenantId)->delete();
                $deleted['products'] = Product::forTenant($tenantId)->forceDelete();
            }

            if (in_array('custom_fields', $scopes, true)) {
                $deleted['custom_fields'] = CustomField::forTenant($tenantId)->delete();
            }

            if (in_array('config', $scopes, true)) {
                $deleted['config'] = Config::forTenant($tenantId)->delete();
            }
        });

        return $deleted;
    }
}
