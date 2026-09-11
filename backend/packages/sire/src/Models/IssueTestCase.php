<?php

namespace Sire\Models;

use Sire\Models\Concerns\RecordsSireAudit;
use Sire\Models\Concerns\BelongsToSireTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * SIRE — one test case against one issue.
 *
 * Named IssueTestCase rather than TestCase to keep it clearly distinct from
 * PHPUnit's TestCase in a codebase where both appear.
 *
 * A CORE model: test cases belong to the QA workflow, and QA is core. Generation
 * is one way a draft arrives; a test typed by hand is indistinguishable except for
 * `source`.
 */
class IssueTestCase extends Model
{
    use HasFactory;

    /** The package ships its own factories; Laravel's default guess looks under App\. */
    protected static function newFactory(): \Sire\Database\Factories\IssueTestCaseFactory
    {
        return \Sire\Database\Factories\IssueTestCaseFactory::new();
    }
    use RecordsSireAudit;
    use BelongsToSireTenant;
    use SoftDeletes;

    public const SOURCE_AI    = 'ai_suggested';
    public const SOURCE_HUMAN = 'human';

    public const DRAFT   = 'draft';
    public const ACTIVE  = 'active';
    public const REMOVED = 'removed';

    public const PASSED  = 'passed';
    public const FAILED  = 'failed';
    public const BLOCKED = 'blocked';
    public const SKIPPED = 'skipped';

    /** The only values a human may record. There is no "auto-passed". */
    public const RESULTS = [self::PASSED, self::FAILED, self::BLOCKED, self::SKIPPED];

    public const PHASE_DEVELOPER = 'developer';
    public const PHASE_QA        = 'qa';
    public const PHASE_BOTH      = 'both';
    public const PHASES = [self::PHASE_DEVELOPER, self::PHASE_QA, self::PHASE_BOTH];

    protected $table = 'sire_test_cases';

    protected $guarded = ['id'];

    protected $casts = ['executed_at' => 'datetime', 'sort_order' => 'integer'];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(HostUser::class, 'executed_by');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(HostUser::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::ACTIVE);
    }

    public function scopeUnrun($query)
    {
        return $query->active()->whereNull('result');
    }

    public function wasGenerated(): bool
    {
        return $this->source === self::SOURCE_AI;
    }

    /**
     * A generated test is still a real test once accepted. What matters is that
     * its RESULT came from a person, which is true of every row: `result` is only
     * ever written by SireTestCaseService::record().
     */
    public function hasHumanResult(): bool
    {
        return $this->result !== null && $this->executed_by !== null;
    }
}
