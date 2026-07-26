<?php

namespace App\Models\Customer;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ClientPreAlert extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id','client_id','tracking_number','courier_company','supplier','purchase_price','delivery_date','status','description','created_by'];

    protected $casts = ['purchase_price'=>'decimal:2','delivery_date'=>'date'];
}
