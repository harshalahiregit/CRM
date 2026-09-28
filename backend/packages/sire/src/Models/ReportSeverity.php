<?php

namespace Sire\Models;

use Sire\Models\Concerns\BelongsToSireTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * SIRE — per-tenant severity, carrying its own SLA targets.
 *
 * `level` is the sort and comparison key, never `code`. A workspace may call its
 * top band Critical, Sev 1 or Blocker and may have three bands or six; everything
 * downstream — urgency styling, the release gate, risk scoring — reads position in
 * the scale rather than a name.
 *
 * A null target means "no SLA for this severity", exactly as Helpdesk treats it.
 * Targets are never copied onto an issue: SLA state is computed at read time, so
 * changing a target changes what it means everywhere at once.
 */
class ReportSeverity extends Model
{
    use HasFactory;

    /** The package ships its own factories; Laravel's default guess looks under App\. */
    protected static function newFactory(): \Sire\Database\Factories\ReportSeverityFactory
    {
        return \Sire\Database\Factories\ReportSeverityFactory::new();
    }
    use BelongsToSireTenant;
    use SoftDeletes;

    protected $table = 'sire_severities';

    protected $guarded = ['id'];

    protected $casts = [
        'level'                     => 'integer',
        'ack_target_minutes'        => 'integer',
        'triage_target_minutes'     => 'integer',
        'resolve_target_minutes'    => 'integer',
        'requires_closure_approval' => 'boolean',
        'auto_escalate'             => 'boolean',
        'is_active'                 => 'boolean',
    ];

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class, 'severity_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Ordered lowest to highest, which is the order a picker should offer. */
    public function scopeByLevel($query)
    {
        return $query->orderBy('level');
    }

    public function hasSla(): bool
    {
        return $this->ack_target_minutes !== null || $this->resolve_target_minutes !== null;
    }
}
