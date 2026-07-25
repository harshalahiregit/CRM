<?php

namespace App\Models\Accounts;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Vendor-bill classification master (Settings → Bill Categories). */
class BillCategory extends Model
{
    use BelongsToTenant;

    protected $table = 'acc_bill_categories';

    protected $fillable = ['tenant_id', 'name', 'active'];

    protected $casts = ['active' => 'boolean'];
}
