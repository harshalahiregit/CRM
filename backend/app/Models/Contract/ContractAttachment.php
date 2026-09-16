<?php

namespace App\Models\Contract;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A document attached to the contract. */
class ContractAttachment extends Model
{
    use BelongsToTenant;

    protected $table = 'contract_attachments';

    protected $fillable = ['tenant_id', 'contract_id', 'name', 'path', 'mime', 'size', 'uploaded_by'];

    protected $casts = ['size' => 'integer'];

    /** The stored path is a server detail; the browser gets a route, not a disk. */
    protected $hidden = ['path'];

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }
}
