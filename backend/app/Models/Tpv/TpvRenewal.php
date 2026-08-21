<?php

namespace App\Models\Tpv;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A TPV renewal/extension assessment (Sangoe TPV §28). */
class TpvRenewal extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $table = 'tpv_renewals';

    public const STATUSES = ['Pending', 'Assessed', 'Decided'];

    public const DECISIONS = [
        'Renew', 'Renew_With_Conditions', 'Extend', 'Requalify', 'Replace', 'Suspend', 'Exit',
    ];

    protected $fillable = [
        'tenant_id', 'reference', 'vendor_id', 'contract_id', 'due_date', 'assessment', 'status',
        'decision', 'conditions', 'new_end_date', 'decided_by', 'decided_at', 'notes', 'created_by',
    ];

    protected $casts = [
        'due_date' => 'date',
        'new_end_date' => 'date',
        'decided_at' => 'datetime',
        'assessment' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (TpvRenewal $r) {
            if (empty($r->reference)) {
                $year = date('Y');
                $n = static::withTrashed()->where('tenant_id', $r->tenant_id)
                    ->whereYear('created_at', $year)->count() + 1;
                $r->reference = 'REN-'.$year.'-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
