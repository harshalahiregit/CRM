<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'name', 'email', 'password',
        'role', 'internal_role', 'department', 'status', 
        'vendor_type', 'tpv_type', 'access_expires_at', 
        'phone', 'company', 'designation', 'avatar', 'meta',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'access_expires_at' => 'date',
            'password'          => 'hashed',
            'meta'              => 'array',
        ];
    }

    /* ── Relationships ──────────────────────── */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /* ── Role helpers ───────────────────────── */
    public function isAdmin():            bool { return $this->role === 'admin'; }
    public function isStaff():            bool { return $this->role === 'staff'; }
    public function isHRExecutive():      bool { return $this->role === 'staff' && $this->internal_role === 'hr_executive'; }
    public function isHiringManager():    bool { return $this->role === 'staff' && $this->internal_role === 'hiring_manager'; }
    public function isVendor():           bool { return $this->role === 'vendor'; }
    public function isThirdPartyVendor(): bool { return $this->role === 'third_party_vendor'; }
    public function isClient():           bool { return $this->role === 'client'; }
    public function isActive():           bool { return $this->status === 'active'; }
    public function isPending():          bool { return $this->status === 'pending'; }

    /* ── Scopes ─────────────────────────────── */
    public function scopeActive($query)        { return $query->where('status', 'active'); }
    public function scopePending($query)       { return $query->where('status', 'pending'); }
    public function scopeOfTenant($q, $tid)    { return $q->where('tenant_id', $tid); }
}
