<?php

namespace App\Models\Shared;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** One user's vote for one option. Unique per (option, user). */
class PollVote extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'poll_id', 'poll_option_id', 'user_id'];

    public function poll()
    {
        return $this->belongsTo(Poll::class);
    }

    public function option()
    {
        return $this->belongsTo(PollOption::class, 'poll_option_id');
    }
}
