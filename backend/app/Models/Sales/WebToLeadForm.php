<?php

namespace App\Models\Sales;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;
use App\Models\Traits\BelongsToTenant;

class WebToLeadForm extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'web_to_lead_forms';

    protected $fillable = [
        'tenant_id', 'form_key', 'name', 'form_data', 'lead_source_id',
        'lead_status_id', 'assigned_to', 'success_message', 'redirect_url',
        'allow_duplicate', 'recaptcha_enabled', 'is_active', 'submissions_count', 'created_by',
    ];

    protected $casts = [
        'form_data'         => 'array',
        'allow_duplicate'   => 'boolean',
        'recaptcha_enabled' => 'boolean',
        'is_active'         => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (WebToLeadForm $form) {
            if (empty($form->form_key)) {
                $form->form_key = Str::random(32);
            }
        });
    }

    public function source()
    {
        return $this->belongsTo(LeadSource::class, 'lead_source_id');
    }

    public function status()
    {
        return $this->belongsTo(LeadStatus::class, 'lead_status_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
