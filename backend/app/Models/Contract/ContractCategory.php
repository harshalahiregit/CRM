<?php

namespace App\Models\Contract;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A kind of contract (Service Agreement, NDA, Supply). Creatable inline from the form. */
class ContractCategory extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'contract_categories';

    protected $fillable = ['tenant_id', 'name', 'description', 'created_by'];

    public function contracts()
    {
        return $this->hasMany(Contract::class, 'contract_category_id');
    }
}
