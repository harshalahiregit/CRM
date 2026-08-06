<?php

namespace App\Models\Shared;

use Illuminate\Database\Eloquent\Model;

/** One choice within a poll. Vote rows point here. */
class PollOption extends Model
{
    protected $fillable = ['poll_id', 'label', 'position'];

    protected $casts = [
        'position' => 'integer',
    ];

    public function poll()
    {
        return $this->belongsTo(Poll::class);
    }

    public function votes()
    {
        return $this->hasMany(PollVote::class);
    }
}
