<?php

namespace App\Models\Contract;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One page of terms.
 *
 * The brief asks for 2 to 10+ pages of clauses, so the long form is stored as
 * ordered pages rather than one blob: it is what the PDF paginates on, and it
 * lets a single clause be edited without rewriting the whole agreement.
 */
class ContractPage extends Model
{
    use BelongsToTenant;

    protected $table = 'contract_pages';

    protected $fillable = ['tenant_id', 'contract_id', 'sort_order', 'title', 'content'];

    protected $casts = ['sort_order' => 'integer'];

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }
}
