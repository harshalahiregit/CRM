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
        'public_token',
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
            if (empty($contract->public_token)) {
                $contract->public_token = \Illuminate\Support\Str::random(40);
            }
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

    public function comments()
    {
        return $this->hasMany(ContractComment::class, 'contract_id')->latest();
    }

    public function pages()
    {
        return $this->morphMany(ContentPage::class, 'pageable')->orderBy('sort_order');
    }

    /** Full renewal chain (walks renewed_from_id both directions, cycle-safe). */
    public function renewalChain(): array
    {
        $chain = [$this];
        $seen = [$this->id];

        $cursor = $this;
        while ($cursor->renewed_from_id && ! in_array($cursor->renewed_from_id, $seen)) {
            $cursor = static::find($cursor->renewed_from_id);
            if (! $cursor) break;
            $seen[] = $cursor->id;
            array_unshift($chain, $cursor);
        }

        $cursor = $this;
        while (true) {
            $next = static::where('renewed_from_id', $cursor->id)->whereNotIn('id', $seen)->first();
            if (! $next) break;
            $seen[] = $next->id;
            $chain[] = $next;
            $cursor = $next;
        }

        return $chain;
    }
}
