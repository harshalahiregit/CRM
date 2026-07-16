<?php

namespace App\Models\Sales;

use App\Models\Customer\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Traits\BelongsToTenant;
use App\Models\Traits\LogsSalesActivity;

class SalesContract extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant, LogsSalesActivity;

    protected $table = 'sales_contracts';

    protected $fillable = [
        'tenant_id', 'reference_no', 'subject', 'client_id', 'contract_type_id',
        'value', 'currency', 'start_date', 'end_date', 'description', 'status',
        'is_renewed', 'renewed_from_id', 'renewal_notice_days', 'expiry_reminder_sent',
        'signature_data', 'signed_at', 'signed_by_name', 'attachments', 'version', 'created_by',
    ];

    protected $casts = [
        'value'                => 'decimal:2',
        'start_date'           => 'date',
        'end_date'             => 'date',
        'is_renewed'           => 'boolean',
        'expiry_reminder_sent' => 'boolean',
        'signed_at'            => 'datetime',
        'attachments'          => 'array',
        'version'              => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (SalesContract $contract) {
            if (empty($contract->reference_no) && $contract->tenant_id) {
                $year  = now()->format('Y');
                $count = static::withTrashed()
                    ->where('tenant_id', $contract->tenant_id)
                    ->whereYear('created_at', $year)
                    ->count() + 1;
                $contract->reference_no = 'CON-' . $year . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    /* ── Relationships ─────────────────────── */
    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function type()
    {
        return $this->belongsTo(ContractType::class, 'contract_type_id');
    }

    public function renewedFrom()
    {
        return $this->belongsTo(SalesContract::class, 'renewed_from_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
