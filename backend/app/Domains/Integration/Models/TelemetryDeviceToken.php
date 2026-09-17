<?php

namespace App\Domains\Integration\Models;

use App\Domains\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * STOS-INT — one rotatable credential for one GPS unit (T-07).
 *
 * The plaintext token exists for exactly one moment: the response to the call
 * that issued it. Everything after that works from the hash.
 */
class TelemetryDeviceToken extends Model
{
    use BelongsToCompany;

    protected $table = 'telemetry_device_tokens';

    /**
     * A recognisable prefix so a leaked token is identifiable on sight.
     *
     * The point is not secrecy — it is that somebody who finds this in a log,
     * a support ticket or a public repository can tell what they are looking at
     * and revoke it, instead of it reading as an anonymous blob of base64.
     */
    public const PREFIX = 'stos_dev_';

    protected $fillable = [
        'company_id', 'device_id', 'label',
        'token_hash', 'last_used_at', 'revoked_at', 'revoked_reason', 'created_by',
    ];

    /** The hash never leaves the server, so it never leaves the model either. */
    protected $hidden = ['token_hash'];

    protected $casts = [
        'company_id'   => 'integer',
        'created_by'   => 'integer',
        'last_used_at' => 'datetime',
        'revoked_at'   => 'datetime',
    ];

    /** 32 bytes of CSPRNG output behind a readable prefix. */
    public static function generate(): string
    {
        return self::PREFIX.Str::random(48);
    }

    /**
     * How a presented token is turned into something comparable.
     *
     * SHA-256, not bcrypt: this runs on every ping from every unit in the
     * fleet, and a deliberately slow hash here would be a denial of service we
     * built ourselves. There is no user-chosen entropy to protect.
     */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
