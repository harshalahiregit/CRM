<?php

namespace App\Models\Compliance;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Support\Compliance\RiskBand;
use App\Support\Compliance\TemplateStatus as Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A reusable checklist form definition.
 *
 * Generic by design — nothing here knows about vendors. The same engine backs
 * HSSE site walks, HR exit checklists and anything else that is "a scored
 * questionnaire attached to a record".
 */
class ComplianceTemplate extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $table = 'compliance_templates';

    protected $fillable = [
        'tenant_id','created_by','code','name','category','description',
        'version','status','definition','thresholds',
    ];

    protected $casts = [
        'definition' => 'array',
        'thresholds' => 'array',
        'version'    => 'integer',
    ];

    protected $appends = ['status_label', 'question_count'];

    /* ── Code auto-generation ─────────────────────── */
    protected static function booted(): void
    {
        static::creating(function (ComplianceTemplate $t) {
            if (empty($t->code)) {
                $seq = static::withTrashed()->where('tenant_id', $t->tenant_id)->count() + 1;
                $t->code = sprintf('CHK-%s-%03d', date('Y'), $seq);
            }
        });
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function checklists()
    {
        return $this->hasMany(ComplianceChecklist::class, 'compliance_template_id');
    }

    public function getStatusLabelAttribute(): string
    {
        return Status::label($this->status);
    }

    public function getQuestionCountAttribute(): int
    {
        return collect($this->definition['sections'] ?? [])
            ->sum(fn ($s) => count($s['questions'] ?? []));
    }

    /** Band cut-offs for this template, defaults filled in. */
    public function bandThresholds(): array
    {
        return RiskBand::thresholds($this->thresholds);
    }

    public function scopeIssuable($query)
    {
        return $query->whereIn('status', Status::ISSUABLE);
    }
}
