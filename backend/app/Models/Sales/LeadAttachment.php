<?php

namespace App\Models\Sales;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** A file attached to a lead. Mirrors ClientAttachment so storage behaves identically. */
class LeadAttachment extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'lead_id', 'file_name', 'file_path', 'mime_type', 'file_size', 'created_by',
    ];

    protected $appends = ['url'];

    public function getUrlAttribute(): ?string
    {
        return $this->file_path ? Storage::disk('public')->url($this->file_path) : null;
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
