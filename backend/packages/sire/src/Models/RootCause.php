<?php

namespace Sire\Models;

use Sire\Models\Concerns\RecordsSireAudit;
use Sire\Models\Concerns\BelongsToSireTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * SIRE — root cause analysis, one per issue.
 *
 * `category` is a real column because defect trends group by it. Contributing
 * factors and the five whys are json: ordered prose, displayed and never filtered.
 */
class RootCause extends Model
{
    use RecordsSireAudit;
    use BelongsToSireTenant;
    use SoftDeletes;

    public const CATEGORIES = [
        'code', 'design', 'requirements', 'data', 'configuration',
        'infrastructure', 'third_party', 'process', 'human_error',
        'testing_gap', 'unknown',
    ];

    /**
     * The three techniques the specification names.
     *
     * A team that ran a fishbone and had to record it as five sequential whys has
     * not recorded a fishbone -- they have flattened a many-branched cause map
     * into a chain. Which method was used is part of the finding.
     */
    public const METHOD_FIVE_WHYS = 'five_whys';
    public const METHOD_FISHBONE  = 'fishbone';
    public const METHOD_FTA       = 'fta';

    public const METHODS = [self::METHOD_FIVE_WHYS, self::METHOD_FISHBONE, self::METHOD_FTA];

    public const METHOD_LABELS = [
        self::METHOD_FIVE_WHYS => '5 Whys',
        self::METHOD_FISHBONE  => 'Fishbone (Ishikawa)',
        self::METHOD_FTA       => 'Fault Tree Analysis',
    ];

    /** The six classic Ishikawa branches, offered as a starting point only. */
    public const FISHBONE_BRANCHES = [
        'people', 'process', 'technology', 'data', 'environment', 'external',
    ];

    protected $table = 'sire_root_causes';

    protected $guarded = ['id'];

    protected $casts = [
        'contributing_factors' => 'array',
        'five_whys'            => 'array',
        'analysis'             => 'array',
        'confirmed_at'         => 'datetime',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(HostUser::class, 'confirmed_by');
    }

    /** A confirmed RCA is the one that counts in reporting. */
    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    public function scopeConfirmed($query)
    {
        return $query->whereNotNull('confirmed_at');
    }
}
