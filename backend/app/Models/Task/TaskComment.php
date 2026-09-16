<?php

namespace App\Models\Task;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A comment on a task.
 *
 * The author is polymorphic: staff and TPVs are Users (`user_id` + the relation
 * below), a Purchase vendor is not (see the
 * 2026_12_24_000001 migration for why). Read the author through `author_label`
 * — never through `user->name` — so a vendor's comment does not render as
 * "Unknown" on the admin side.
 */
class TaskComment extends Model
{
    protected $fillable = [
        'tenant_id', 'task_id', 'user_id', 'content',
        'author_kind', 'author_id', 'author_name',
    ];

    /** Both are computed; the client should never have to join anything. */
    protected $appends = ['author_label', 'is_vendor_author'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Files attached to THIS comment (task files carrying this comment_id). */
    public function attachments()
    {
        return $this->hasMany(TaskFile::class, 'comment_id');
    }

    /**
     * Who wrote it, in one string.
     *
     * A User resolves live, so a rename is reflected everywhere. A non-User
     * author has only the snapshot taken at write time — there is no relation to
     * follow, by design.
     */
    public function getAuthorLabelAttribute(): string
    {
        return $this->user?->name ?? $this->author_name ?? 'Unknown';
    }

    /** Lets the UI mark an outside voice as an outside voice. */
    public function getIsVendorAuthorAttribute(): bool
    {
        return $this->author_kind === 'purchase_vendor';
    }
}
