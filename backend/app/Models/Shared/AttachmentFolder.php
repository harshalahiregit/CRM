<?php

namespace App\Models\Shared;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A folder in a subject's file area. Self-referential: parent_id null is the root.
 */
class AttachmentFolder extends Model
{
    use BelongsToTenant;

    protected $table = 'attachment_folders';

    protected $fillable = [
        'tenant_id', 'attachable_type', 'attachable_id', 'parent_id', 'name', 'created_by',
    ];

    public function attachable()
    {
        return $this->morphTo();
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('name');
    }

    public function files()
    {
        return $this->hasMany(Attachment::class, 'folder_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForSubject($query, string $type, int $id)
    {
        return $query->where('attachable_type', $type)->where('attachable_id', $id);
    }
}
