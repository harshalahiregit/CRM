<?php

namespace Sire\Models;

use Sire\Models\Concerns\BelongsToSireTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SIRE — one indexed term of one issue.
 *
 * A derived artefact, not a record of anything. Truncate this table and
 * `sire:index-issues` rebuilds it; nothing is lost but time.
 */
class IssueToken extends Model
{
    use BelongsToSireTenant;

    public const WEIGHT_TITLE = 3;
    public const WEIGHT_BODY  = 1;

    protected $table = 'sire_issue_tokens';

    protected $guarded = ['id'];

    protected $casts = ['weight' => 'integer'];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }
}
