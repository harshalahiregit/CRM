<?php

namespace App\Models\Customer;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ClientContract extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id','client_id','subject','contract_type','value','start_date','end_date','status','description','created_by'];

    protected $casts = ['value'=>'decimal:2','start_date'=>'date','end_date'=>'date'];
}
