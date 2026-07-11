<?php

namespace App\Models\Hr;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A candidate document (offer letter, ID proof, certificate, additional resume,
 * …). Files live on the private `hr_documents` disk; this row is the metadata.
 */
class HrCandidateDocument extends Model
{
    protected $table = 'hr_candidate_documents';

    /** Allowed document categories — mirrored on the frontend. */
    public const TYPES = ['resume', 'offer', 'id_proof', 'certificate', 'other'];

    protected $fillable = [
        'tenant_id', 'candidate_id', 'uploaded_by', 'type',
        'original_name', 'path', 'size_kb', 'mime',
    ];

    protected $casts = [
        'size_kb' => 'integer',
    ];

    public function candidate()
    {
        return $this->belongsTo(HrCandidate::class, 'candidate_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
