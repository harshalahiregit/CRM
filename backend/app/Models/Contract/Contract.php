<?php

namespace App\Models\Contract;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Support\Contract\ContractParty;
use App\Support\Contract\ContractStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A formal agreement with a client or a vendor.
 *
 * Company-wide and self-contained: Sales, Purchase and TPV keep their own
 * contract features untouched, and this module links out to their records
 * through the `party` morph rather than borrowing a column from any of them.
 */
class Contract extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'reference_no', 'title', 'description', 'contract_category_id',
        'party_type', 'party_id', 'party_name', 'party_email',
        'value', 'currency', 'start_date', 'end_date',
        'renewal_notice_days', 'renewal_reminder_sent_at', 'renewed_from_id',
        'status', 'sent_at', 'fully_signed_at', 'created_by',
    ];

    protected $casts = [
        'value'                    => 'decimal:2',
        'start_date'               => 'date',
        'end_date'                 => 'date',
        'sent_at'                  => 'datetime',
        'fully_signed_at'          => 'datetime',
        'renewal_reminder_sent_at' => 'datetime',
        'renewal_notice_days'      => 'integer',
    ];

    /**
     * The signing link is a bearer credential — holding it is authority to sign.
     * Hidden so it can never ride along in a list or detail payload; it is
     * disclosed only by the endpoint that deliberately returns it.
     */
    protected $hidden = ['public_token'];

    protected $appends = ['is_fully_signed', 'days_to_expiry'];

    protected static function booted(): void
    {
        static::creating(function (Contract $c) {
            if (empty($c->public_token)) {
                $c->public_token = Str::random(48);
            }

            if (empty($c->reference_no) && $c->tenant_id) {
                // Highest issued + 1 rather than count + 1: counting gives the
                // same number again the moment one is deleted.
                $year = now()->format('Y');
                $last = static::withTrashed()
                    ->where('tenant_id', $c->tenant_id)
                    ->where('reference_no', 'like', "CTR-{$year}-%")
                    ->orderByDesc('reference_no')
                    ->value('reference_no');
                $next = $last ? ((int) Str::afterLast($last, '-')) + 1 : 1;
                $c->reference_no = sprintf('CTR-%s-%04d', $year, $next);
            }
        });
    }

    /* ── Relationships ──────────────────────────────────────────── */

    public function category()
    {
        return $this->belongsTo(ContractCategory::class, 'contract_category_id');
    }

    /** The customer or vendor — a Client, Vendor or PurchaseVendor. */
    public function party()
    {
        return $this->morphTo('party');
    }

    public function pages()
    {
        return $this->hasMany(ContractPage::class)->orderBy('sort_order');
    }

    public function signatures()
    {
        return $this->hasMany(ContractSignature::class);
    }

    public function discussions()
    {
        return $this->hasMany(ContractDiscussion::class)->latest();
    }

    public function attachments()
    {
        return $this->hasMany(ContractAttachment::class);
    }

    public function links()
    {
        return $this->hasMany(ContractLink::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ── Derived state ──────────────────────────────────────────── */

    /** Both sides signed. This is what anything downstream should read. */
    public function getIsFullySignedAttribute(): bool
    {
        return $this->fully_signed_at !== null;
    }

    /**
     * Days until it lapses. Negative once it has.
     *
     * Null when there is no end date — an open-ended agreement does not expire,
     * and returning 0 would make it look like it expires today.
     */
    public function getDaysToExpiryAttribute(): ?int
    {
        return $this->end_date
            ? (int) now()->startOfDay()->diffInDays($this->end_date->startOfDay(), false)
            : null;
    }

    /** The signature row for one party, if it exists yet. */
    public function signatureFor(string $party): ?ContractSignature
    {
        return $this->signatures->firstWhere('signer_party', $party);
    }

    /* ── Scopes ─────────────────────────────────────────────────── */

    public function scopeOpen($q)
    {
        return $q->whereIn('status', ContractStatus::OPEN);
    }

    /**
     * Live agreements whose end date falls inside their own notice window.
     *
     * The window is per row — a contract carries the number of days' warning it
     * wants — so the comparison has to be done in SQL against that column rather
     * than against one cutoff computed in PHP.
     *
     * Date arithmetic is the one thing SQL dialects never agree on, and this
     * application runs on SQLite (both in production and under test), so the
     * MySQL-only DATE_ADD/DATEDIFF this was first written with threw a
     * QueryException on every call rather than merely failing the tests.
     */
    public function scopeExpiringSoon($q)
    {
        $today = now()->toDateString();

        $q->open()->whereNotNull('end_date')->whereDate('end_date', '>=', $today);

        return match ($q->getConnection()->getDriverName()) {
            'sqlite' => $q->whereRaw(
                'julianday(end_date) - julianday(?) <= renewal_notice_days', [$today]),
            'pgsql' => $q->whereRaw(
                "end_date <= ?::date + (renewal_notice_days || ' days')::interval", [$today]),
            default => $q->whereRaw(
                'DATEDIFF(end_date, ?) <= renewal_notice_days', [$today]),
        };
    }

    /** Which parties still have to sign. */
    public function pendingParties(): array
    {
        $signed = $this->signatures->whereNotNull('signed_at')->pluck('signer_party')->all();

        return array_values(array_diff(ContractParty::ALL, $signed));
    }
}
