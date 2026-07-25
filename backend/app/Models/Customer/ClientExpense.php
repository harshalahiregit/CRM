<?php

namespace App\Models\Customer;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ClientExpense extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id','client_id','name','category','amount','date','payment_mode','billable','note','created_by','project_id'];

    protected $casts = ['amount'=>'decimal:2','date'=>'date','billable'=>'boolean'];
}
