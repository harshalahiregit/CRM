<?php

namespace App\Models\Hr;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One complainant link to one case.
 *
 * A BEARER CREDENTIAL, and the code says so plainly rather than implying
 * otherwise: whoever holds the link is the complainant as far as this server
 * is concerned. There is no password, no second factor and no device binding,
 * because there is no delivery channel we are permitted to assume and nothing
 * to bind to. What protects it is expiry, revocation, throttling and the fact
 * that every successful read is recorded.
 *
 * THE RAW TOKEN IS NOT HERE. Only its sha256 is stored, and token_hash is
 * hidden from serialisation so it cannot ride out in a model returned by
 * accident. Comparison is by hash; the raw value is unrecoverable the moment
 * the issuing response is sent.
 *
 * A token is LIVE, or it is not. Expired, revoked and superseded are three
 * different histories and one identical answer, because telling a caller which
 * of them applies tells them the token was real.
 */
class HrPoshCaseToken extends Model
{
    use BelongsToTenant;

    protected $table = 'hr_posh_case_tokens';

    protected $fillable = [
        'tenant_id', 'case_id', 'token_hash', 'label',
        'issued_at', 'issued_by', 'expires_at',
        'revoked_at', 'revoked_by', 'revoked_reason',
        'presented_at', 'last_used_at', 'use_count',
    ];

    protected $casts = [
        'issued_at'    => 'datetime',
        'expires_at'   => 'datetime',
        'revoked_at'   => 'datetime',
        'presented_at' => 'datetime',
        'last_used_at' => 'datetime',
        'use_count'    => 'integer',
    ];

    /**
     * Never serialised, even when something returns this model by mistake.
     *
     * The hash is not the token and cannot be replayed, but it is the lookup
     * key for every row in this table and has no business leaving the server.
     */
    protected $hidden = ['token_hash'];

    public function case()
    {
        return $this->belongsTo(HrPoshCase::class, 'case_id');
    }

    /** Still usable. The only question the portal ever asks. */
    public function isLive(): bool
    {
        return $this->revoked_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->revoked_at === null
            && $this->expires_at !== null
            && $this->expires_at->isPast();
    }

    /**
     * The stored form of a raw token.
     *
     * One place, so a lookup and an insert can never disagree about what is
     * being compared.
     */
    public static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }
}
