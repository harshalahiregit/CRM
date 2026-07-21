<?php

namespace App\Models\Compliance;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Support\Compliance\SignatureTier;
use Illuminate\Database\Eloquent\Model;

/**
 * One link in the issuer → manager → head chain.
 *
 * Append-only: a rejection is never edited into an approval, and reopening a
 * rejected checklist leaves the rejection on the record.
 */
class ComplianceSignature extends Model
{
    use BelongsToTenant;

    protected $table = 'compliance_signatures';

    protected $fillable = [
        'tenant_id','compliance_checklist_id','user_id',
        'tier','action','remarks','signature_path','signed_at','segregation_overridden',
    ];

    protected $casts = [
        'signed_at'              => 'datetime',
        'segregation_overridden' => 'boolean',
    ];

    protected $appends = ['tier_label'];

    public function checklist()
    {
        return $this->belongsTo(ComplianceChecklist::class, 'compliance_checklist_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getTierLabelAttribute(): string
    {
        return SignatureTier::label($this->tier);
    }
}
