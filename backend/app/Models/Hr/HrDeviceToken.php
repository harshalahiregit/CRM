<?php

namespace App\Models\Hr;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A device somebody can be reached on.
 *
 * Registered by the app on every launch and on every token refresh, so the same
 * device arrives repeatedly with the same token — `remember()` is written to be
 * called that often and do nothing expensive when nothing has changed.
 */
class HrDeviceToken extends Model
{
    protected $table = 'hr_device_tokens';

    protected $fillable = [
        'tenant_id', 'user_id', 'token', 'token_hash',
        'platform', 'device_name', 'last_used_at',
    ];

    protected $casts = ['last_used_at' => 'datetime'];

    /** The raw token never needs to leave the server. */
    protected $hidden = ['token', 'token_hash'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record that this device belongs to this person, now.
     *
     * Keyed on the token, not on the user: FCM hands the same token back to the
     * same install, and if a colleague signs in on a shared handset the device
     * must MOVE to them rather than notify both. Two people sharing one token is
     * how somebody reads a message that was not theirs.
     */
    public static function remember(int $tenantId, int $userId, string $token, ?string $platform = null, ?string $deviceName = null): self
    {
        return static::updateOrCreate(
            ['token_hash' => hash('sha256', $token)],
            [
                'tenant_id'    => $tenantId,
                'user_id'      => $userId,
                'token'        => $token,
                'platform'     => $platform,
                'device_name'  => $deviceName,
                'last_used_at' => now(),
            ],
        );
    }

    /** Every device a person can be reached on. */
    public static function forUser(int $tenantId, int $userId): array
    {
        return static::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->pluck('token')
            ->all();
    }

    /**
     * Drop a token FCM has told us is dead.
     *
     * Keeping it means retrying a device that no longer exists on every single
     * send, forever, and a queue that looks like it is failing when it is not.
     */
    public static function forget(string $token): void
    {
        static::where('token_hash', hash('sha256', $token))->delete();
    }
}
