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
     'confidential', 'customer_visible',];

    protected $appends = ['url'];

    /**
     * The authenticated download endpoint, not a storage URL.
     *
     * Storage::url() only means anything on a public disk, and these files are
     * deliberately not on one. Returning a path the client must fetch WITH its
     * token keeps the single access check in the controller — an accessor that
     * hands out a bypass is how the files became world-readable in the first
     * place.
     */
    public function getUrlAttribute(): ?string
    {
        return $this->file_path
            ? "/api/customers/{$this->client_id}/attachments/{$this->id}/download"
            : null;
    }
}
