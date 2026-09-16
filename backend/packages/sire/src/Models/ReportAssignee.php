<?php

namespace Sire\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Sire\Models\Concerns\BelongsToSireTenant;

/**
 * SIRE — somebody else working an issue, alongside the person who owns it.
 *
 * The OWNER is sire_reports.assignee_id and always will be: every workflow guard
 * is written against it, and a defect with four equal owners has none. These are
 * the others -- they see it on their queue and hear about it, and any of them can
 * be promoted to owner by assigning them.
 */
class ReportAssignee extends Model
{
    use BelongsToSireTenant;
    use HasFactory;

    protected $table = 'sire_report_assignees';

    protected $guarded = ['id'];

    /** The package ships its own factories; Laravel's default guess looks under App\. */
    protected static function newFactory(): \Sire\Database\Factories\ReportAssigneeFactory
    {
        return \Sire\Database\Factories\ReportAssigneeFactory::new();
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(HostUser::class, 'user_id');
    }
}
