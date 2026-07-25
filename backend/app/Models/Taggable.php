<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Join row between a Tag and any taggable record (task, project, …). */
class Taggable extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'tag_id', 'taggable_type', 'taggable_id'];

    public function tag()
    {
        return $this->belongsTo(Tag::class);
    }
}
