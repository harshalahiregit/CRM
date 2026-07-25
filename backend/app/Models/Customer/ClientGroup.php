<?php

namespace App\Models\Customer;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ClientGroup extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name'];

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'client_group_client');
    }
}
