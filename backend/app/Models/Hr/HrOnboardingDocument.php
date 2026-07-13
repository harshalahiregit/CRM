<?php

namespace App\Models\Hr;

use Illuminate\Database\Eloquent\Model;

/**
 * A document uploaded by the candidate during onboarding. Files live on the
 * private `hr_documents` disk; this row is the metadata. Tenant-scoped.
 */
class HrOnboardingDocument extends Model
{
    protected $table = 'hr_onboarding_documents';

    protected $fillable = [
        'tenant_id', 'onboarding_id', 'type', 'original_name', 'path', 'size_kb', 'mime',
        'verified', 'status', 'remarks',
    ];

    protected $casts = [
        'size_kb'  => 'integer',
        'verified' => 'boolean',
    ];

    public function onboarding()
    {
        return $this->belongsTo(HrOnboarding::class, 'onboarding_id');
    }
}
