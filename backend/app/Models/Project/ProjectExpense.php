<?php

namespace App\Models\Project;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProjectExpense extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'project_id', 'title', 'category', 'expense_category_id',
        'amount', 'currency', 'tax_percent', 'expense_date', 'reference_no',
        'payment_mode', 'purchase_vendor_id', 'note', 'billable',
        'receipt_path', 'receipt_name', 'created_by',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'tax_percent'  => 'decimal:2',
        'expense_date' => 'date',
        'billable'     => 'boolean',
    ];

    /** The gross figure, so the list and the total never work it out twice. */
    protected $appends = ['total_amount', 'has_receipt'];

    public function getTotalAmountAttribute(): float
    {
        return round((float) $this->amount * (1 + ((float) $this->tax_percent / 100)), 2);
    }

    /** Whether a receipt is attached — the path itself is never sent to the browser. */
    public function getHasReceiptAttribute(): bool
    {
        return ! empty($this->receipt_path);
    }

    public function expenseCategory()
    {
        return $this->belongsTo(\App\Models\ExpenseCategory::class, 'expense_category_id');
    }

    /** Never serialised — it is a private disk path, not a URL. */
    protected $hidden = ['receipt_path'];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
