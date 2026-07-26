<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use App\Models\User;
use App\Support\Hr\CompanyAccountStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class HrExternalCompany extends Model
{
    use Auditable, HasFactory;

    protected $table = 'hr_external_companies';

    protected $fillable = [
        'tenant_id', 'company_code', 'company_type', 'name', 'industry', 'company_size',
        'contact_person', 'contact_email', 'contact_phone',
        'secondary_contact_person', 'secondary_contact_email', 'secondary_contact_phone',
        'location', 'billing_address', 'office_address',
        'website', 'logo_path', 'gst_number', 'pan_number',
        'status', 'notes', 'about', 'notification_settings', 'branding', 'public_token', 'created_by',
    ];

    protected $casts = [
        'notification_settings' => 'array',
        'branding'              => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $c) {
            if (empty($c->public_token)) {
                $c->public_token = Str::random(48);
            }
            if (empty($c->company_code) && $c->tenant_id) {
                $c->company_code = self::nextCode((int) $c->tenant_id);
            }
        });
    }

    /** Next per-tenant company code, e.g. CMP-0001. */
    public static function nextCode(int $tenantId): string
    {
        $n = self::where('tenant_id', $tenantId)->count() + 1;

        return 'CMP-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    public function isActive(): bool
    {
        return $this->status === CompanyAccountStatus::ACTIVE;
    }

    public function hiringRequests()
    {
        return $this->hasMany(HrHiringRequest::class, 'external_company_id');
    }

    /** Portal login(s) belonging to this company. */
    public function users()
    {
        return $this->hasMany(User::class, 'external_company_id');
    }
}
