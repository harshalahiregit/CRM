<?php

namespace App\Models\Sales;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Traits\BelongsToTenant;

class ContractType extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'contract_types';

    protected $fillable = ['tenant_id', 'name'];

    public function contracts()
    {
        return $this->hasMany(SalesContract::class, 'contract_type_id');
    }
}
