<?php

namespace App\Models\Inventory;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A stock document: receiving voucher, delivery voucher, internal delivery note,
 * or loss & adjustment. Paperwork until posted — see VoucherService::post().
 */
class Voucher extends Model
{
    use SoftDeletes, BelongsToTenant;

    protected $table = 'inventory_vouchers';

    /** type => [label, code prefix, movement type written on post] */
    public const TYPES = [
        'receipt'         => ['Inventory receiving voucher', 'RCV', 'receive'],
        'delivery'        => ['Inventory delivery voucher',  'DLV', 'issue'],
        'internal'        => ['Internal delivery note',      'INT', 'transfer'],
        'loss_adjustment' => ['Loss & adjustment',           'ADJ', null],
    ];

    protected $fillable = [
        'tenant_id', 'type', 'code', 'status', 'date_c', 'date_add', 'description',
        'warehouse_id',
        'supplier_name', 'supplier_code', 'deliver_name', 'invoice_no', 'expiry_date',
        'expense_type', 'department', 'requester', 'buyer_id', 'pr_order_id', 'project_id',
        'customer_name', 'customer_id', 'address',
        'adjustment_type', 'reason',
        'total_goods', 'total_tax', 'total_discount', 'total_amount', 'inventory_value',
        'staff_id', 'route_point', 'posted_at', 'posted_by', 'created_by',
    ];

    protected $casts = [
        'date_c'       => 'date',
        'date_add'     => 'date',
        'expiry_date'  => 'date',
        'posted_at'    => 'datetime',
        'total_goods'  => 'decimal:2',
        'total_discount'  => 'decimal:2',
        'inventory_value' => 'decimal:2',
        'total_tax'    => 'decimal:2',
        'total_amount' => 'decimal:2',
        'approved_at'  => 'datetime',
        'rejected_at'  => 'datetime',
        'submitted_at' => 'datetime',
    ];

    /**
     * The document lifecycle. `pending_approval` and `approved` only appear in
     * workspaces that switched a rule on in Settings > Approval; everywhere else
     * a draft goes straight to posted, exactly as it always did.
     */
    public const STATUSES = ['draft', 'pending_approval', 'approved', 'posted', 'cancelled'];

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function items()
    {
        return $this->hasMany(VoucherItem::class, 'voucher_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function staff()
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type][0] ?? $this->type;
    }

    protected $appends = ['type_label'];

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }
}
