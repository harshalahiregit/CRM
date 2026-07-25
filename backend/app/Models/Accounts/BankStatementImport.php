<?php

namespace App\Models\Accounts;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One statement-import batch (a CSV/XLSX upload) for a bank account. */
class BankStatementImport extends Model
{
    use BelongsToTenant;

    protected $table = 'acc_bank_statement_imports';

    protected $fillable = [
        'tenant_id', 'bank_account_id', 'source', 'file_name',
        'from_date', 'to_date', 'lines_count', 'status', 'created_by', 'imported_at',
    ];

    protected $casts = [
        'from_date'   => 'date',
        'to_date'     => 'date',
        'imported_at' => 'datetime',
    ];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class, 'import_id');
    }
}
