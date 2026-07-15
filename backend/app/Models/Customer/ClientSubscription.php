<?php

namespace App\Models\Customer;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ClientSubscription extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id','client_id','name','amount','quantity','interval','status','next_billing_date','description','created_by'];

    protected $casts = ['amount'=>'decimal:2','quantity'=>'integer','next_billing_date'=>'date'];
}
