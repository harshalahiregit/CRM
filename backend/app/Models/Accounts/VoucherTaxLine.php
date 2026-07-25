<?php

namespace App\Models\Accounts;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The tax breakup captured for a voucher (metadata alongside the balanced
 * journal). One row per component (cgst/sgst/igst/cess/tds/tcs). GSTR-1/3B and TDS
 * returns are derived from these rows joined to the voucher date/party.
 */
class VoucherTaxLine extends Model
{
    use BelongsToTenant;

    protected $table = 'acc_voucher_tax_lines';

    protected $fillable = [
        'tenant_id', 'voucher_id', 'tax_type', 'rate', 'taxable_amount', 'tax_amount',
        'hsn_sac_id', 'tds_section_code', 'place_of_supply_state', 'direction',
    ];

    protected $casts = [
        'rate'           => 'decimal:3',
        'taxable_amount' => 'decimal:2',
        'tax_amount'     => 'decimal:2',
    ];

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }
}
