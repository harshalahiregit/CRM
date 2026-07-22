<?php

namespace App\Services\Accounts;

use App\Exceptions\BusinessException;
use App\Models\Accounts\GstRate;
use App\Models\Accounts\HsnSac;
use App\Models\Accounts\TdsSection;

/**
 * CRUD for the configurable tax masters (spec §8: rates/thresholds updatable
 * without a deploy). Rows are effective-dated; nothing hard-codes a rate.
 */
class TaxMasterService
{
    /* ── GST rates ────────────────────────────────────────────── */
    public function gstRates(int $tenantId)
    {
        return GstRate::forTenant($tenantId)->orderByDesc('rate_percent')->get();
    }

    public function saveGstRate(?GstRate $rate, array $data, int $tenantId): GstRate
    {
        $payload = array_intersect_key($data, array_flip([
            'name', 'rate_percent', 'cgst', 'sgst', 'igst', 'cess', 'effective_from', 'effective_to', 'is_active',
        ]));

        if ($rate) {
            $this->assert($rate, $tenantId);
            $rate->update($payload);
            return $rate;
        }
        return GstRate::create(['tenant_id' => $tenantId, ...$payload]);
    }

    public function deleteGstRate(GstRate $rate, int $tenantId): void
    {
        $this->assert($rate, $tenantId);
        $rate->delete();
    }

    /* ── HSN / SAC ────────────────────────────────────────────── */
    public function hsnSac(int $tenantId, ?string $search = null)
    {
        return HsnSac::forTenant($tenantId)->with('defaultRate:id,name')->search($search)->orderBy('code')->paginate(100);
    }

    public function saveHsnSac(?HsnSac $row, array $data, int $tenantId): HsnSac
    {
        $payload = array_intersect_key($data, array_flip(['code', 'description', 'type', 'default_gst_rate_id', 'is_active']));
        if ($row) {
            $this->assert($row, $tenantId);
            $row->update($payload);
            return $row;
        }
        return HsnSac::create(['tenant_id' => $tenantId, ...$payload]);
    }

    public function deleteHsnSac(HsnSac $row, int $tenantId): void
    {
        $this->assert($row, $tenantId);
        $row->delete();
    }

    /* ── TDS sections ─────────────────────────────────────────── */
    public function tdsSections(int $tenantId)
    {
        return TdsSection::forTenant($tenantId)->orderBy('section_code')->get();
    }

    public function saveTdsSection(?TdsSection $row, array $data, int $tenantId): TdsSection
    {
        $payload = array_intersect_key($data, array_flip([
            'section_code', 'description', 'rate_percent', 'threshold', 'effective_from', 'effective_to', 'is_active',
        ]));
        if ($row) {
            $this->assert($row, $tenantId);
            $row->update($payload);
            return $row;
        }
        return TdsSection::create(['tenant_id' => $tenantId, ...$payload]);
    }

    public function deleteTdsSection(TdsSection $row, int $tenantId): void
    {
        $this->assert($row, $tenantId);
        $row->delete();
    }

    private function assert($model, int $tenantId): void
    {
        if ($model->tenant_id !== $tenantId) {
            throw new BusinessException('Not found.');
        }
    }
}
