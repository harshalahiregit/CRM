<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * An employee's tax declaration for one financial year.
 *
 * The TDS engine reads this; it never writes to it. A missing declaration is a
 * valid state, not an error — it means "no deductions claimed", and tax is
 * computed on the full taxable salary.
 */
class HrInvestmentDeclaration extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'hr_investment_declarations';

    public const DRAFT = 'Draft';
    public const SUBMITTED = 'Submitted';
    public const VERIFIED = 'Verified';
    public const REJECTED = 'Rejected';

    public const STATUSES = [self::DRAFT, self::SUBMITTED, self::VERIFIED, self::REJECTED];

    public const OLD = 'old';
    public const NEW = 'new';
    public const REGIMES = [self::OLD, self::NEW];

    protected $fillable = [
        'tenant_id', 'employee_id', 'financial_year', 'regime', 'status',
        'previous_employer_income', 'previous_employer_tds', 'previous_employer_pf', 'previous_employer_pt',
        'hra', 'declared_total', 'verified_total', 'remarks',
        'submitted_at', 'verified_at', 'verified_by', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'hra'            => 'array',
        'declared_total' => 'decimal:2',
        'verified_total' => 'decimal:2',
        'submitted_at'   => 'datetime',
        'verified_at'    => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(HrInvestmentDeclarationItem::class, 'declaration_id');
    }

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    /**
     * Only a VERIFIED declaration may reduce tax.
     *
     * A draft or merely submitted claim is an intention, not evidence. Letting it
     * reduce TDS would under-deduct all year and leave the employee with a bill.
     */
    public function countsForTax(): bool
    {
        return $this->status === self::VERIFIED;
    }

    /**
     * The amount a section contributes: the verified figure once checked, else the
     * declared one. Falls back to declared so a mid-year estimate still works while
     * proofs are outstanding — but only on a declaration that already counts.
     */
    public function amountFor(string $section): float
    {
        return round((float) $this->items
            ->where('section', $section)
            ->sum(fn ($i) => $i->verified_amount !== null ? (float) $i->verified_amount : (float) $i->declared_amount), 2);
    }
}
