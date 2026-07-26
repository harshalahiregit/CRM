<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A workspace-wide tag. Attached to anything via the taggables table, so adding
 * tags to a new module needs no schema change — just a taggable_type string.
 */
class Tag extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'color'];

    public function taggables()
    {
        return $this->hasMany(Taggable::class);
    }
}
