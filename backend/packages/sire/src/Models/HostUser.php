<?php

namespace Sire\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SIRE — a read-only window onto the host's users table.
 *
 * WHY THIS EXISTS
 *
 * SIRE models need to display who owns a release or confirmed a root cause, and
 * doing that efficiently means an eager-loadable relation: `with('owner:id,name')`
 * on a list of fifty releases is one query, where fifty provider lookups would
 * be fifty.
 *
 * But a relation needs a class, and `belongsTo(\App\Models\User::class)` is
 * exactly the hard dependency on a specific host model that the SDK exists to
 * remove. So SIRE owns this one instead, and points it at whatever table the
 * host names in config.
 *
 * DISPLAY ONLY. NOT IDENTITY.
 *
 * Nothing in SIRE authorizes, scopes or decides anything from this model. That
 * is SireUserProvider's job, and it returns SireUserIdentity. This is for
 * rendering a name beside an avatar, and it is guarded accordingly:
 *
 *   - read-only: no fillable fields, and saving throws;
 *   - three columns visible, so a careless `toArray()` cannot leak a password
 *     hash or an email into an API response.
 *
 * A host whose users are not an Eloquent table at all should implement
 * SireUserProvider and ignore these relations; the names then come from the
 * snapshots SIRE stores on audit entries and comments, which is why those
 * columns exist.
 */
class HostUser extends Model
{
    public $timestamps = false;

    /**
     * Only ever these three. Not a convenience — a deliberate ceiling on what
     * SIRE can accidentally expose about a person.
     */
    protected $visible = ['id', 'name', 'role'];

    protected $guarded = ['*'];

    public function getTable(): string
    {
        return (string) config('sire.tables.users.name', 'users');
    }

    protected static function booted(): void
    {
        // SIRE never writes to the host's users table. Anything that tries is a
        // bug, and should say so loudly rather than succeed quietly.
        $refuse = function (): void {
            throw new \RuntimeException(
                'SIRE: HostUser is read-only. SIRE never writes to the host users table.'
            );
        };

        static::creating($refuse);
        static::updating($refuse);
        static::deleting($refuse);
    }
}
