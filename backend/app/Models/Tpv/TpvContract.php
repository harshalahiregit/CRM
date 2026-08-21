<?php

namespace App\Models\Tpv;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A TPV commercial contract (Sangoe TPV §8) — the vendor relationship's actual
 * engagement. TPV-owned; distinct from Purchase's `purchase_contracts`.
 */
class TpvContract extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $table = 'tpv_contracts';

    public const STATUSES = ['Draft', 'Active', 'Expiring', 'Expired', 'Renewed', 'Terminated', 'Closed'];

    protected $fillable = [
        'tenant_id', 'reference', 'vendor_id', 'project_id', 'contract_type', 'title', 'scope',
        'start_date', 'end_date', 'contract_value', 'currency', 'payment_terms', 'sla', 'kpi',
        'penalties', 'insurance_requirements', 'hse_clauses', 'compliance_clauses', 'renewal_terms',
        'status', 'notes', 'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'contract_value' => 'decimal:2',
    ];

    protected $appends = ['is_expired'];

    protected static function booted(): void
    {
        static::creating(function (TpvContract $contract) {
            if (empty($contract->reference)) {
                $contract->reference = self::nextReference((int) $contract->tenant_id);
            }
        });
    }

    /** CT-YYYY-### per tenant per year. */
    public static function nextReference(int $tenantId): string
    {
        $year = date('Y');
        $n = static::withTrashed()->where('tenant_id', $tenantId)
            ->whereYear('created_at', $year)->count() + 1;

        return 'CT-'.$year.'-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function workOrders()
    {
        return $this->hasMany(TpvWorkOrder::class, 'contract_id');
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->end_date !== null
            && $this->end_date->isPast()
            && ! in_array($this->status, ['Expired', 'Terminated', 'Closed', 'Renewed'], true);
    }
}
