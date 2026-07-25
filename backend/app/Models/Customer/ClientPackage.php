<?php

namespace App\Models\Customer;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ClientPackage extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id','client_id','package_number','description','courier_company','supplier','value','weight','status','date','created_by'];

    protected $casts = ['value'=>'decimal:2','date'=>'date'];
}
