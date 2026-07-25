<?php

namespace App\Models\Sales;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ProposalOtp extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'proposal_id', 'code_hash', 'expires_at',
        'attempts', 'consumed_at', 'access_token_hash', 'access_expires_at',
    ];

    protected $casts = [
        'expires_at'        => 'datetime',
        'consumed_at'       => 'datetime',
        'access_expires_at' => 'datetime',
    ];

    protected $hidden = ['code_hash', 'access_token_hash'];
}
