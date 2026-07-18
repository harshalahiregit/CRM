<?php

namespace App\Models\Hr;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Request-scoped document (JD/NDA/PO/Agreement/Scope/SOW/Other), stored on the
 * existing private hr_documents disk. Owns only the metadata; file plumbing
 * reuses the same pattern as HrCandidateDocument.
 */
class HrHiringRequestDocument extends Model
{
    protected $table = 'hr_hiring_request_documents';

    public const TYPES = ['jd', 'nda', 'po', 'agreement', 'scope', 'sow', 'other'];

    protected $fillable = [
        'tenant_id', 'hiring_request_id', 'uploader_kind', 'uploaded_by',
        'type', 'original_name', 'path', 'size_kb', 'mime',
    ];

    protected $casts = [
        'size_kb' => 'integer',
    ];

    public function hiringRequest()
    {
        return $this->belongsTo(HrHiringRequest::class, 'hiring_request_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
