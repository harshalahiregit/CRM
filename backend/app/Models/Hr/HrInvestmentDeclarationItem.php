<?php

namespace App\Models\Hr;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One declared line under one section.
 *
 * `verified_amount` is null until payroll checks the proof. Null and 0.00 mean
 * different things — "not yet checked" versus "checked and disallowed" — so it is
 * never defaulted to zero.
 */
class HrInvestmentDeclarationItem extends Model
{
    use BelongsToTenant;

    protected $table = 'hr_investment_declaration_items';

    protected $fillable = [
        'tenant_id', 'declaration_id', 'section', 'particulars',
        'declared_amount', 'verified_amount', 'proof_submitted', 'proof_path', 'remarks',
    ];

    protected $casts = [
        'declared_amount' => 'decimal:2',
        'verified_amount' => 'decimal:2',
        'proof_submitted' => 'boolean',
    ];

    public function declaration()
    {
        return $this->belongsTo(HrInvestmentDeclaration::class, 'declaration_id');
    }
}
