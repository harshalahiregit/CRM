<?php

namespace App\Services\Accounts;

use App\Exceptions\BusinessException;
use App\Models\Accounts\AuditLog;
use App\Models\Accounts\CompanyProfile;
use App\Models\Accounts\FinancialYear;
use App\Models\Accounts\NumberingSeries;
use App\Models\Accounts\VoucherType;
use Illuminate\Support\Facades\Log;

/**
 * Company profile, financial years, numbering series, voucher types, and the
 * read-only audit-log surface. Tax masters / period-locking beyond FY close are
 * later phases.
 */
class SettingsService
{
    public function __construct(private AuditLogger $audit)
    {
    }

    /* ── Company profile ──────────────────────────────────────── */
    public function companyProfile(int $tenantId): ?CompanyProfile
    {
        return CompanyProfile::forTenant($tenantId)->first();
    }

    public function saveCompanyProfile(array $data, int $tenantId, ?int $userId): CompanyProfile
    {
        $profile = CompanyProfile::forTenant($tenantId)->first();
        $before = $profile?->toArray();

        $profile = CompanyProfile::updateOrCreate(
            ['tenant_id' => $tenantId],
            array_intersect_key($data, array_flip([
                'legal_name', 'trade_name', 'pan', 'gstin', 'tan',
                'state_code', 'entity_type', 'books_begin_date', 'base_currency',
            ])),
        );

        $this->audit->log($tenantId, $userId, 'company_profile', $profile->id, $before ? 'update' : 'create', $before, $profile->toArray());
        Log::channel('accounts')->info('Company profile saved', ['tenant_id' => $tenantId]);

        return $profile;
    }

    /* ── Financial years ──────────────────────────────────────── */
    public function financialYears(int $tenantId)
    {
        return FinancialYear::forTenant($tenantId)->orderByDesc('start_date')->get();
    }

    public function createFinancialYear(array $data, int $tenantId): FinancialYear
    {
        if ($data['start_date'] >= $data['end_date']) {
            throw new BusinessException('Start date must be before end date.');
        }

        $overlap = FinancialYear::forTenant($tenantId)
            ->where('start_date', '<=', $data['end_date'])
            ->where('end_date', '>=', $data['start_date'])
            ->exists();
        if ($overlap) {
            throw new BusinessException('This period overlaps an existing financial year.');
        }

        return FinancialYear::create([
            'tenant_id'  => $tenantId,
            'start_date' => $data['start_date'],
            'end_date'   => $data['end_date'],
            'is_closed'  => false,
        ]);
    }

    /** Toggle the period lock (spec §7.3). */
    public function setFinancialYearClosed(FinancialYear $fy, bool $closed, int $tenantId, ?int $userId): FinancialYear
    {
        if ($fy->tenant_id !== $tenantId) {
            throw new BusinessException('Financial year not found.');
        }

        $fy->update(['is_closed' => $closed]);
        $this->audit->log($tenantId, $userId, 'financial_year', $fy->id, 'update', null, ['is_closed' => $closed]);

        return $fy;
    }

    /* ── Voucher types & numbering ────────────────────────────── */
    public function voucherTypes(int $tenantId)
    {
        return VoucherType::forTenant($tenantId)->with('numberingSeries')->orderBy('name')->get();
    }

    public function numberingSeries(int $tenantId)
    {
        return NumberingSeries::forTenant($tenantId)->orderBy('voucher_type_code')->get();
    }

    public function updateNumberingSeries(NumberingSeries $series, array $data, int $tenantId): NumberingSeries
    {
        if ($series->tenant_id !== $tenantId) {
            throw new BusinessException('Numbering series not found.');
        }

        $series->update(array_intersect_key($data, array_flip([
            'prefix', 'suffix', 'next_number', 'reset_frequency', 'width',
        ])));

        return $series;
    }

    /* ── Audit trail (read-only) ──────────────────────────────── */
    public function auditLogs(int $tenantId, array $filters)
    {
        $query = AuditLog::forTenant($tenantId)->with('user:id,name');

        if (! empty($filters['entity_type'])) {
            $query->where('entity_type', $filters['entity_type']);
        }
        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        return $query->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 50));
    }
}
