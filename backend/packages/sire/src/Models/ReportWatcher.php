<?php

namespace Sire\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Sire\Models\Concerns\BelongsToSireTenant;

/**
 * SIRE — a person who asked to hear about an issue they are not assigned to.
 *
 * Subscription, not permission. Watching an issue does not grant sight of it:
 * every read still goes through the ordinary capability and tenant checks, so
 * adding somebody as a watcher can never widen what they can see. It only
 * decides whether they are told when something happens.
 *
 * No soft deletes. Unwatching is not history worth keeping -- it is somebody
 * turning off a notification -- and a trail of them would be noise in a table
 * read on every transition.
 */
class ReportWatcher extends Model
{
    use BelongsToSireTenant;
    use HasFactory;

    protected $table = 'sire_report_watchers';

    protected $guarded = ['id'];

    /** The package ships its own factories; Laravel's default guess looks under App\. */
    protected static function newFactory(): \Sire\Database\Factories\ReportWatcherFactory
    {
        return \Sire\Database\Factories\ReportWatcherFactory::new();
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
