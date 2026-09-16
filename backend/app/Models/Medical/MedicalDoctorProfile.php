<?php

namespace App\Models\Medical;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The practising identity of a doctor login — licence, council, signature.
 *
 * Kept apart from `users` on purpose: the user row is the shared login that
 * every module depends on, and none of this belongs to it. A doctor without a
 * profile can sign in but cannot sign a certificate, which is the correct
 * failure — an unlicensed prescription is worse than none.
 */
class MedicalDoctorProfile extends Model
{
    use BelongsToTenant;

    protected $table = 'medical_doctor_profiles';

    protected $fillable = [
        'tenant_id', 'user_id',
        'license_no', 'council', 'qualification', 'designation',
        'clinic_name', 'clinic_address', 'phone',
        'signature_path', 'stamp_path', 'photo_path',
        'modules', 'is_active',
    ];

    protected $casts = [
        'modules'   => 'array',
        'is_active' => 'boolean',
    ];

    protected $appends = ['is_signable'];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Serves the TPV side, the Purchase side, or both. */
    public function servesModule(string $module): bool
    {
        $modules = $this->modules;

        // An unset list means "both" — the common case, and the answer the
        // senior gave: one login covering both vendor sides.
        return empty($modules) || in_array($module, $modules, true);
    }

    /**
     * A certificate carries a licence number by law. Until the profile has one,
     * this doctor can examine but cannot issue.
     */
    public function isSignable(): bool
    {
        return $this->is_active && filled($this->license_no);
    }

    public function getIsSignableAttribute(): bool
    {
        return $this->isSignable();
    }
}
