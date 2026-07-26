<?php

namespace App\Models\Customer;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ClientAttachment extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'client_id', 'file_name', 'file_path', 'mime_type', 'file_size', 'created_by',
    ];

    protected $appends = ['url'];

    public function getUrlAttribute(): ?string
    {
        return $this->file_path ? Storage::url($this->file_path) : null;
    }
}
