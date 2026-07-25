<?php

namespace App\Services\Accounts;

use App\Exceptions\BusinessException;
use App\Exceptions\UnauthorizedTenantException;
use App\Models\Accounts\NumberingSeries;
use App\Models\Accounts\Voucher;
use App\Models\Accounts\VoucherType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Voucher-type master. System types (the nine seeded accounting kinds) can be
 * renamed and have their numbering edited, but not deleted — the automated
 * posting bridges resolve them by `code`. Custom types are user-added named
 * vouchers with their own gap-less numbering series; the manual voucher entry
 * screen supplies the balanced legs, so a custom type carries no posting logic.
 */
class VoucherTypeService
{
    public function list(int $tenantId)
    {
        return VoucherType::forTenant($tenantId)
            ->with('numberingSeries:id,prefix,next_number')
            ->orderByDesc('is_system')->orderBy('name')
            ->get()
            ->map(fn (VoucherType $t) => [
                'id'         => $t->id,
                'code'       => $t->code,
                'name'       => $t->name,
                'is_system'  => $t->is_system,
                'active'     => $t->active,
                'affects_gst'=> $t->affects_gst,
                'prefix'     => $t->numberingSeries?->prefix,
                'next_number'=> $t->numberingSeries?->next_number,
            ]);
    }

    public function create(array $data, int $tenantId): VoucherType
    {
        $name = trim($data['name']);
        $code = $this->uniqueCode($name, $tenantId);
        $prefix = strtoupper(trim($data['prefix'] ?? '')) ?: strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name), 0, 3));

        return DB::transaction(function () use ($tenantId, $name, $code, $prefix, $data) {
            $series = NumberingSeries::create([
                'tenant_id'         => $tenantId,
                'voucher_type_code' => $code,
                'prefix'            => $prefix,
                'suffix'            => '',
                'next_number'       => 1,
                'reset_frequency'   => 'yearly',
                'width'             => 4,
            ]);

            return VoucherType::create([
                'tenant_id'           => $tenantId,
                'code'                => $code,
                'name'                => $name,
                'is_system'           => false,
                'active'              => true,
                'numbering_series_id' => $series->id,
                'affects_gst'         => (bool) ($data['affects_gst'] ?? false),
                'affects_stock'       => false,
            ]);
        });
    }

    public function update(VoucherType $type, array $data, int $tenantId): VoucherType
    {
        $this->assertTenant($type, $tenantId);

        $type->update(array_filter([
            'name'        => $data['name'] ?? null,
            'active'      => array_key_exists('active', $data) ? (bool) $data['active'] : null,
            'affects_gst' => array_key_exists('affects_gst', $data) ? (bool) $data['affects_gst'] : null,
        ], fn ($v) => $v !== null));

        // Prefix edits flow to the linked numbering series.
        if (array_key_exists('prefix', $data) && $type->numberingSeries) {
            $type->numberingSeries->update(['prefix' => strtoupper(trim($data['prefix']))]);
        }

        return $type->fresh('numberingSeries');
    }

    public function delete(VoucherType $type, int $tenantId): void
    {
        $this->assertTenant($type, $tenantId);

        if ($type->is_system) {
            throw new BusinessException('System voucher types cannot be deleted (they can be renamed or deactivated).');
        }
        if (Voucher::where('voucher_type_id', $type->id)->exists()) {
            throw new BusinessException('This voucher type is already used by posted vouchers — deactivate it instead of deleting.');
        }

        DB::transaction(function () use ($type) {
            $type->numberingSeries?->delete();
            $type->delete();
        });
    }

    private function uniqueCode(string $name, int $tenantId): string
    {
        $base = Str::slug($name, '_') ?: 'voucher';
        $code = $base;
        $i = 2;
        while (VoucherType::forTenant($tenantId)->where('code', $code)->exists()) {
            $code = $base.'_'.$i++;
        }
        return $code;
    }

    private function assertTenant(VoucherType $type, int $tenantId): void
    {
        if ($type->tenant_id !== $tenantId) {
            throw new UnauthorizedTenantException();
        }
    }
}
