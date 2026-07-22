<?php

namespace App\Models\Sales;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ContractComment extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'contract_id', 'user_id', 'author_name', 'body'];

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
