<?php

namespace App\Models\Contract;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A soft link from a contract to a task, note or project.
 *
 * No foreign key, deliberately: this module must not own a constraint on
 * another module's table, and a link whose target has since been deleted should
 * degrade to "no longer available" rather than stop the contract loading.
 * `label` is a snapshot of the target's name for exactly that case.
 */
class ContractLink extends Model
{
    use BelongsToTenant;

    protected $table = 'contract_links';

    protected $fillable = ['tenant_id', 'contract_id', 'linkable_type', 'linkable_id', 'label', 'created_by'];

    protected $casts = ['linkable_id' => 'integer'];

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }
}
