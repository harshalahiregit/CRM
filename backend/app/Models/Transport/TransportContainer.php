<?php

namespace App\Models\Transport;

use App\Models\Transport\Concerns\RecordsTransportAudit;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The container MASTER — one row per physical transport unit.
 *
 * `STOS-REQ-MDM-008` ("Maintain container master/reference", P0). Exists by the
 * architecture approval of D-39; the master + association shape is D-40's Option B1.
 *
 * STOS-CTD §8: a container is "the physical transport unit", as distinct from the
 * consignment, "the commercial/operational shipment being transported". This row
 * outlives any one consignment — that is the whole reason it is a master and not
 * a column on the shipment.
 *
 * ── THE NORMALISATION IS THIS CLASS'S REAL JOB ───────────────────────────
 * See normalise(). Every container search this module will ever run goes through
 * it, and a normalisation bug is SILENT: the row is there, and the search simply
 * does not find it. It is therefore a pure static function, tested one
 * transformation at a time rather than as one combined case.
 *
 * ── WHAT IS DELIBERATELY ABSENT ─────────────────────────────────────────
 *   status      derived, never stored — D-44, as for the consignment.
 *   size_feet   invented in an earlier proposal of mine; CTD §6's identity is
 *               Number and Type, and no size field exists in the package.
 *   is_reefer   temperature/genset/reefer is Person 2's (TM-001 §6), and every
 *               source places the idea on the consignment, the trip or the
 *               service requirement — never the container. D-52.
 *   seal_*      STOS-CMP §76/§77, Person 3's. Requested, not built.
 *   format validation  CTD §7 asks for it; no format is specified anywhere, and
 *               ISO 6346's check digit would reject legitimate non-ISO numbers.
 *
 * @property int         $tenant_id
 * @property string      $container_number
 * @property string      $container_number_normalized
 * @property string|null $container_type
 */
class TransportContainer extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant, RecordsTransportAudit;

    protected $table = 'transport_containers';

    protected $fillable = [
        'tenant_id', 'container_number', 'container_type',
        'created_by', 'updated_by',
        // container_number_normalized is NOT fillable. It is derived from
        // container_number by the booted() hook below, so the two can never
        // disagree — a caller that could set it independently could break the
        // search for a row that looks perfectly correct on screen.
    ];

    /**
     * Keep the normalised key in step with the number, always.
     *
     * On the model rather than in the service because the invariant belongs to
     * the row, not to one code path: a seeder, a console command, a test
     * factory or a future import all get it for free, and none of them can
     * produce a row whose search key disagrees with its number.
     *
     * ── WHY THIS IS A HOOK AND active_container_key IS A GENERATED COLUMN ──
     * This feature derives two values two different ways, and the difference is
     * not a preference — it is what each engine can express.
     *
     *   active_container_key  CASE WHEN detached_at IS NULL THEN container_id
     *                         ELSE NULL END. Verified identical on MySQL 8.0.46
     *                         and sqlite 3.45.1, so the DATABASE owns it.
     *
     *   this key              needs trim + strip-non-alphanumeric + uppercase.
     *                         MySQL 8 has REGEXP_REPLACE; SQLITE HAS NO SUCH
     *                         FUNCTION (verified: "no such function:
     *                         REGEXP_REPLACE"). A generated column would need
     *                         two different expressions, and the suite runs on
     *                         the engine that cannot express it — the D-51 trap,
     *                         where a constraint is real in production and
     *                         absent from every test.
     *
     * So the rule that both engines agree on lives in the database, and the one
     * they do not lives in PHP, where there is exactly one implementation.
     *
     * ── THE RESIDUAL, STATED PLAINLY ─────────────────────────────────────
     * A saving() hook covers ELOQUENT WRITES ONLY. A raw DB::table() insert, a
     * raw SQL import or a migration writing rows directly BYPASSES IT, and can
     * produce a row whose search key disagrees with its number — a container
     * that looks perfectly correct on screen and cannot be found by search.
     *
     * active_container_key has no such gap: the database computes it however
     * the row arrives.
     *
     * This is acceptable today because nothing writes containers that way. It
     * will not be acceptable for a bulk container import, which is the obvious
     * next thing to write here. Anyone doing that must either go through this
     * model or call normalise() themselves — and should read this before
     * writing it, not after.
     */
    protected static function booted(): void
    {
        static::saving(function (TransportContainer $container) {
            $container->container_number_normalized = self::normalise($container->container_number);
        });
    }

    /**
     * STOS-CTD §7 — "be normalized for search", while the original entered
     * value is retained separately.
     *
     * Exactly three transformations, in this order. Each is here for a reason
     * and none is cosmetic:
     *
     *   1. TRIM. A trailing space from a paste or a barcode scan is invisible
     *      on screen and makes the value unequal to itself. Nothing downstream
     *      can see this one, so it must not survive.
     *
     *   2. STRIP EVERYTHING THAT IS NOT A LETTER OR A DIGIT. Container numbers
     *      are written by hand and by machine in several shapes for the same
     *      box — "ABCD1234567", "ABCD 123456 7", "ABCD-123456-7". Removing
     *      spaces, hyphens, dots and slashes makes those one key. This also
     *      covers internal whitespace, so it subsumes any "collapse spaces"
     *      rule rather than needing one.
     *
     *   3. UPPERCASE. The owner prefix is alphabetic and is conventionally
     *      capitalised; "abcd1234567" is the same box as "ABCD1234567".
     *      Applied LAST so it operates on a string already free of separators,
     *      and with a plain ASCII strtoupper — mb_strtoupper would be pointless
     *      after step 2 has removed every non-ASCII character anyway.
     *
     * Deliberately NOT done here:
     *   - no length check and no ISO 6346 check digit. §7 asks for
     *     "configurable format validation" and no document configures it;
     *     rejecting a customer's real container number is a worse failure than
     *     accepting a malformed one. Deferred to Product.
     *   - no truncation. The column is 20 characters and the FormRequest bounds
     *     the input; silently shortening a number here would make two different
     *     boxes collide on one key.
     *
     * Returns '' for null or for a string with nothing alphanumeric in it. The
     * caller decides what that means — the database's NOT NULL is what refuses
     * it — because a normaliser that threw would make every read path a
     * try/catch.
     */
    public static function normalise(?string $number): string
    {
        if ($number === null) {
            return '';
        }

        $trimmed = trim($number);
        $stripped = preg_replace('/[^A-Za-z0-9]/', '', $trimmed) ?? '';

        return strtoupper($stripped);
    }

    /* ── Relations ──────────────────────────────────────────────────── */

    /**
     * Every attachment this container has ever had — STOS-CTD §7's "maintain
     * historical associations". Ordered newest first, because the question
     * asked of a container is almost always "what is it carrying now?".
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(ConsignmentContainer::class, 'container_id')
            ->orderByDesc('attached_at');
    }

    /* ── Scopes. None filters by tenant; they compose AFTER forTenant(). ── */

    /**
     * CTD-001 — "search complete lifecycle using container number".
     *
     * Normalises the TERM as well as the column, so a user pasting
     * "abcd-123456-7" finds a row stored as "ABCD1234567". Exact match, not
     * LIKE: a container number is an identifier, and a partial match would
     * return a different box.
     */
    public function scopeWithNumber(Builder $query, string $number): Builder
    {
        return $query->where('container_number_normalized', self::normalise($number));
    }

    /** Free-text search across a list page, where partial matching IS wanted. */
    public function scopeNumberContains(Builder $query, string $term): Builder
    {
        return $query->where('container_number_normalized', 'like', '%'.self::normalise($term).'%');
    }

    /* ── Derived state ──────────────────────────────────────────────── */

    /** The attachment that is live now, if any. At most one — enforced in the database. */
    public function currentAttachment(): ?ConsignmentContainer
    {
        return $this->attachments()->whereNull('detached_at')->first();
    }

    /** Is this container on a consignment right now? */
    public function isAttached(): bool
    {
        return $this->attachments()->whereNull('detached_at')->exists();
    }
}
