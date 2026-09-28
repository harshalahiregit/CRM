<?php

namespace App\Models\Transport;

use App\Models\Traits\BelongsToTenant;
use App\Support\Transport\TripEventType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * `trip_events` — one thing that happened, from any system. STOS-DB §37.
 *
 * ── APPEND ONLY. CTD §34, AND IT IS ENFORCED RATHER THAN ASKED ──────────
 * "Historical events should not be silently edited. Corrections should create a
 * Correction Event with audit trail." So this model throws on update and on
 * delete, the way TransportAuditLog already does. A correction is a new row
 * whose `corrects_event_id` points at the one it corrects, and both remain
 * readable — which is what makes the correction itself part of the history
 * rather than a quiet overwrite of it.
 *
 * ── TWO TIMES, AND THE DIFFERENCE MATTERS ───────────────────────────────
 * `occurred_at` is when it happened; `recorded_at` is when we heard. STOS-DB
 * §19 asks for both. Every read below orders by the first, because CTD §31 is a
 * chronology of events and not of arrivals: a telemetry batch buffered for an
 * hour would otherwise place an hour of driving after the delivery it preceded.
 *
 * @property int         $tenant_id
 * @property string      $event_type
 * @property string      $category
 * @property string      $source
 * @property string      $summary
 */
class TripEvent extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'trip_events';

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'trip_id', 'order_id', 'consignment_id', 'container_id',
        'event_type', 'category', 'source', 'occurred_at', 'recorded_at',
        'summary', 'detail', 'actor_id', 'actor_name', 'actor_role',
        'corrects_event_id', 'created_by',
    ];

    protected $casts = [
        'trip_id'        => 'integer',
        'order_id'       => 'integer',
        'consignment_id' => 'integer',
        'container_id'   => 'integer',
        'occurred_at'    => 'datetime',
        'recorded_at'    => 'datetime',
        'detail'         => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException(
                'Trip events are immutable (STOS-CTD §34). Record a correction event instead — '
                .'TripEventRecorder::correct().'
            );
        });

        static::deleting(function () {
            throw new RuntimeException(
                'Trip events cannot be deleted (STOS-CTD §34). The timeline is the record; '
                .'a mistake in it is corrected by appending, never by removing.'
            );
        });
    }

    /* ── Relations ──────────────────────────────────────────────────── */

    public function trip(): BelongsTo
    {
        return $this->belongsTo(TransportTrip::class, 'trip_id');
    }

    /** The event this one corrects, when it is a correction. CTD §34. */
    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_event_id');
    }

    /* ── Scopes. Each one is a question the Passport asks. ───────────── */

    public function scopeForTrip(Builder $q, int $tripId): Builder
    {
        return $q->where('trip_id', $tripId);
    }

    public function scopeForContainer(Builder $q, int $containerId): Builder
    {
        return $q->where('container_id', $containerId);
    }

    public function scopeForConsignment(Builder $q, int $consignmentId): Builder
    {
        return $q->where('consignment_id', $consignmentId);
    }

    /** CTD §35's filters. */
    public function scopeInCategory(Builder $q, string $category): Builder
    {
        return $q->where('category', $category);
    }

    /** Chronological, by when it HAPPENED. CTD §31. */
    public function scopeChronological(Builder $q, bool $newestFirst = true): Builder
    {
        return $q->orderBy('occurred_at', $newestFirst ? 'desc' : 'asc')
            // A stable tiebreak: a seeder writing eleven events inside one
            // second would otherwise order them arbitrarily, which looks like a
            // bug to anybody reading the timeline.
            ->orderBy('id', $newestFirst ? 'desc' : 'asc');
    }

    /* ── Derived ────────────────────────────────────────────────────── */

    public function label(): string
    {
        return $this->summary ?: TripEventType::label($this->event_type);
    }

    /** Whether the type is one the registry knows. Drives nothing; reported. */
    public function isRegistered(): bool
    {
        return TripEventType::isKnown($this->event_type);
    }

    public function isCorrection(): bool
    {
        return $this->corrects_event_id !== null;
    }
}
