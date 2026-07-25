<?php

namespace App\Models\Customer;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ClientShipment extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id','client_id','shipment_number','origin','destination','courier_company','weight','value','status','date','created_by'];

    protected $casts = ['value'=>'decimal:2','date'=>'date'];
}
