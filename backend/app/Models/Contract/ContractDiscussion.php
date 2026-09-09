<?php

namespace App\Models\Contract;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One message in the negotiation thread.
 *
 * Internal staff and the counterparty write into the SAME thread -- a
 * negotiation split across two threads is two people talking past each other.
 * `guest_name` is set when the message arrived through the public link, so the
 * page can say who is speaking without inventing a User for an outsider.
 */
class ContractDiscussion extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'contract_discussions';

    protected $fillable = ['tenant_id', 'contract_id', 'user_id', 'guest_name', 'body'];

    protected $appends = ['author_name', 'is_external'];

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getAuthorNameAttribute(): string
    {
        return $this->author?->name ?? ($this->guest_name ?: 'Counterparty');
    }

    /** Drawn differently on both sides, so nobody mistakes who said what. */
    public function getIsExternalAttribute(): bool
    {
        return $this->user_id === null;
    }
}
