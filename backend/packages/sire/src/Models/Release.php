<?php

namespace Sire\Models;

use Sire\Models\Concerns\RecordsSireAudit;
use Sire\Models\Concerns\BelongsToSireTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** SIRE — a release. Issues reference it in four different roles. */
class Release extends Model
{
    use RecordsSireAudit;
    use BelongsToSireTenant;
    use SoftDeletes;

    public const TYPES    = ['major', 'minor', 'patch', 'hotfix'];
    public const STATUSES = ['planned', 'in_progress', 'released', 'rolled_back'];

    protected $table = 'sire_releases';

    protected $guarded = ['id'];

    protected $casts = [
        'release_date' => 'date',
        'released_at'  => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(HostUser::class, 'owner_id');
    }

    /** Issues SHIPPED in this release — the set release notes are built from. */
    public function shippedIssues(): HasMany
    {
        return $this->hasMany(Report::class, 'released_version_id');
    }

    /** Issues this release CAUSED. The regression-by-release number. */
    public function regressionsCaused(): HasMany
    {
        return $this->hasMany(Report::class, 'caused_by_release_id');
    }

    public function releaseNotes(): HasMany
    {
        return $this->hasMany(ReleaseNote::class, 'release_id');
    }
}
