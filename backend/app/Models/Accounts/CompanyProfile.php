<?php

namespace App\Models\Accounts;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Statutory profile of the tenant's business — one row per tenant
 * (tenant = one company). Drives GST place-of-supply, e-invoice latch, etc.
 */
class CompanyProfile extends Model
{
    use BelongsToTenant;

    protected $table = 'acc_company_profiles';

    protected $fillable = [
        'tenant_id', 'legal_name', 'trade_name', 'pan', 'gstin', 'tan',
        'state_code', 'entity_type', 'books_begin_date', 'base_currency',
    ];

    protected $casts = [
        'books_begin_date' => 'date',
    ];
}
