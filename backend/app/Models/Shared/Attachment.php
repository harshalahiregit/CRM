<?php

namespace App\Models\Shared;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One stored file. The bytes are always on our own disk — `source` records only
 * where the file was picked from.
 */
class Attachment extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'attachments';

    /** Import sources. The bytes are ours in every case. */
    public const SOURCES = ['upload', 'google_drive', 'onedrive', 'pcloud'];

    protected $fillable = [
        'tenant_id', 'attachable_type', 'attachable_id', 'folder_id',
        'name', 'disk', 'path', 'mime', 'size', 'source', 'source_ref', 'uploaded_by',
    ];

    protected $casts = ['size' => 'integer'];

    protected $appends = ['size_label'];

    /** The stored path is an internal detail; a download route serves the bytes. */
    protected $hidden = ['path', 'disk'];

    public function attachable()
    {
        return $this->morphTo();
    }

    public function folder()
    {
        return $this->belongsTo(AttachmentFolder::class, 'folder_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function scopeForSubject($query, string $type, int $id)
    {
        return $query->where('attachable_type', $type)->where('attachable_id', $id);
    }

    /** Human size, formatted once here rather than in every client. */
    public function getSizeLabelAttribute(): string
    {
        $b = (int) $this->size;
        if ($b < 1024) {
            return $b.' B';
        }
        foreach (['KB', 'MB', 'GB', 'TB'] as $i => $unit) {
            $v = $b / (1024 ** ($i + 1));
            if ($v < 1024) {
                return round($v, $v < 10 ? 1 : 0).' '.$unit;
            }
        }

        return round($b / (1024 ** 4), 1).' TB';
    }
}
