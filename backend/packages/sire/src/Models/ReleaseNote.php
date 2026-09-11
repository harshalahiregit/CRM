<?php

namespace Sire\Models;

use Sire\Models\Concerns\RecordsSireAudit;
use Sire\Models\Concerns\BelongsToSireTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * SIRE — release notes for one release and one audience.
 *
 * The generated body is STORED, not rendered on demand. A published note must
 * keep saying what it said when it was approved; reopening an issue afterwards
 * must not silently rewrite something customers have already read.
 */
class ReleaseNote extends Model
{
    use HasFactory;

    /** The package ships its own factories; Laravel's default guess looks under App\. */
    protected static function newFactory(): \Sire\Database\Factories\ReleaseNoteFactory
    {
        return \Sire\Database\Factories\ReleaseNoteFactory::new();
    }
    use RecordsSireAudit;
    use BelongsToSireTenant;
    use SoftDeletes;

    public const AUDIENCE_INTERNAL = 'internal';
    public const AUDIENCE_USER     = 'user';
    public const AUDIENCES = [self::AUDIENCE_INTERNAL, self::AUDIENCE_USER];

    public const DRAFT            = 'draft';
    public const PENDING_APPROVAL = 'pending_approval';
    public const APPROVED         = 'approved';
    public const PUBLISHED        = 'published';
    public const STATUSES = [self::DRAFT, self::PENDING_APPROVAL, self::APPROVED, self::PUBLISHED];

    protected $table = 'sire_release_notes';

    protected $guarded = ['id'];

    protected $casts = [
        'sections'     => 'array',
        'generated_at' => 'datetime',
        'approved_at'  => 'datetime',
        'published_at' => 'datetime',
        'issue_count'  => 'integer',
    ];

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'release_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(HostUser::class, 'approved_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(HostUser::class, 'published_by');
    }

    /** Once published, regeneration is refused — see SireReleaseNotesService. */
    public function isFrozen(): bool
    {
        return $this->status === self::PUBLISHED;
    }
}
