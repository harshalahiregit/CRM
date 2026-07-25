<?php

namespace App\Models\Purchase;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Purchase\PurchaseContractStatus as Status;
use App\Support\Purchase\PurchaseContractType as Type;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A master agreement / rate contract with a vendor. Once Active its rate card
 * and dates freeze; POs may reference it for pre-negotiated pricing.
 */
class PurchaseContract extends Model
{
    use Auditable, SoftDeletes, BelongsToTenant;

    protected $table = 'purchase_contracts';

    protected $fillable = [
        'tenant_id','contract_number','title','type','vendor_id','created_by',
        'currency','start_date','end_date','spend_ceiling','consumed_amount',
        'status','terms','document_path','approved_at','approved_by','notes',
    ];

    protected $casts = [
        'start_date'      => 'date',
        'end_date'        => 'date',
        'spend_ceiling'   => 'decimal:2',
        'consumed_amount' => 'decimal:2',
        'approved_at'     => 'datetime',
    ];

    protected $appends = ['status_label', 'type_label', 'is_expired', 'remaining', 'over_ceiling'];

    protected static function booted(): void
    {
        static::creating(function (PurchaseContract $c) {
            if (empty($c->contract_number)) {
                $year  = date('Y');
                $count = static::withTrashed()->where('tenant_id', $c->tenant_id)
                               ->whereYear('created_at', $year)->count() + 1;
                $c->contract_number = 'CTR-'.$year.'-'.str_pad((string) $count, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    public function items()
    {
        return $this->hasMany(PurchaseContractItem::class, 'purchase_contract_id')->orderBy('sort_order');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getStatusLabelAttribute(): string
    {
        return Status::label($this->status);
    }

    public function getTypeLabelAttribute(): string
    {
        return Type::label($this->type);
    }

    public function isEditable(): bool
    {
        return Status::isEditable($this->status);
    }

    /**
     * Read-time truth: an Active contract past its end date is effectively
     * expired even before the nightly sweep persists the status.
     */
    public function getIsExpiredAttribute(): bool
    {
        return $this->end_date !== null
            && $this->end_date->isPast()
            && ! in_array($this->status, [Status::TERMINATED], true);
    }

    /** Remaining spend under the ceiling; null when uncapped. */
    public function getRemainingAttribute(): ?float
    {
        if ($this->spend_ceiling === null) {
            return null;
        }

        return round((float) $this->spend_ceiling - (float) $this->consumed_amount, 2);
    }

    public function getOverCeilingAttribute(): bool
    {
        return $this->spend_ceiling !== null && (float) $this->consumed_amount > (float) $this->spend_ceiling + 0.0001;
    }

    /** A contract POs may actually draw on: Active, in-window, engageable vendor. */
    public function isReferenceable(): bool
    {
        return Status::isReferenceable($this->status) && ! $this->is_expired;
    }

    public function scopeReferenceable($query)
    {
        return $query->where('status', Status::ACTIVE)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', now()));
    }
}
