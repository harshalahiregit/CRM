<?php

namespace App\Models\Hr;

use App\Models\Shared\Attachment;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * One entry in a request's conversation.
 *
 * APPEND ONLY, enforced here rather than by convention. An entry cannot be
 * updated or deleted through Eloquent — a thread that can be quietly edited is
 * worse than no thread, because it looks like evidence and is not.
 *
 * Anything that needs correcting is corrected by adding another entry. That is
 * how a ledger works, and this is a ledger of decisions about money.
 */
class HrRequestMessage extends Model
{
    use BelongsToTenant;

    protected $table = 'hr_request_messages';

    /** Either side wrote it, and both can read it. */
    public const KIND_MESSAGE = 'message';

    /** Admin to admin. The employee never sees these. */
    public const KIND_NOTE = 'note';

    /** The system wrote it, because something happened. */
    public const KIND_EVENT = 'event';

    protected $fillable = [
        'tenant_id', 'subject_type', 'subject_id',
        'author_id', 'kind', 'body', 'event_type', 'meta',
    ];

    protected $casts = ['meta' => 'array'];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('A request message cannot be edited. Add another entry instead.');
        });

        static::deleting(function () {
            throw new RuntimeException('A request message cannot be deleted. Add another entry instead.');
        });
    }

    public function subject()
    {
        return $this->morphTo();
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Files sent with this entry.
     *
     * Points at the existing shared `attachments` table rather than a second
     * store, so a receipt on a message downloads through the same route, obeys
     * the same tenant scoping, and can be listed beside every other file on the
     * request.
     */
    public function attachments()
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /** Everything the employee is allowed to see: messages and events, not notes. */
    public function scopeVisibleToEmployee($query)
    {
        return $query->whereIn('kind', [self::KIND_MESSAGE, self::KIND_EVENT]);
    }

    public function isVisibleToEmployee(): bool
    {
        return $this->kind !== self::KIND_NOTE;
    }
}
