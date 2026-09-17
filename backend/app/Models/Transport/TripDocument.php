<?php

namespace App\Models\Transport;

use App\Models\Transport\Concerns\RecordsTransportAudit;
use App\Models\Traits\BelongsToTenant;
use App\Support\Transport\TripDocumentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * DB-009 `trip_documents` — the paperwork a trip produces.  SNG-TRN-014.
 *
 * Distinct from `transport_documents` (DB-019), which indexes the compliance
 * paperwork master data carries. See the migration for why the registry keeps
 * them apart and why collapsing them would be FORBID-005.
 *
 * ── CTR-012, ENFORCED RATHER THAN DOCUMENTED ─────────────────────────────
 * "Immutable after verification." The file identity — path, name, hash — is
 * frozen the moment a document is verified or rejected, by a model event rather
 * than by convention. `trip_number` is guarded the same way one class over
 * (FLD-006), for the same reason: a rule that only exists in a service is a
 * rule the first ::update() forgets.
 *
 * The guard is on the FILE, not the whole row. `notes` may still be added to a
 * verified POD, because annotating evidence is not altering it.
 *
 * @property int         $tenant_id
 * @property int         $trip_id
 * @property string      $document_type
 * @property string      $status
 * @property string      $file_hash
 */
class TripDocument extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant, RecordsTransportAudit;

    protected $table = 'trip_documents';

    protected $fillable = [
        'tenant_id', 'trip_id', 'document_type',
        'file_path', 'file_name', 'file_mime', 'file_size', 'file_hash',
        'status', 'uploaded_by', 'notes', 'created_by', 'updated_by',
        // verified_by, verified_at and rejection_reason are deliberately NOT
        // fillable. They are written only by TripDocumentService, which checks
        // STT-008's guard and audits the decision. Mass-assignable, a caller
        // could verify a POD by posting a field.
    ];

    protected $casts = [
        'trip_id'     => 'integer',
        'file_size'   => 'integer',
        'verified_at' => 'datetime',
        'verified_by' => 'integer',
        'uploaded_by' => 'integer',
    ];

    protected $appends = ['status_label'];

    /**
     * The file is frozen once a decision has been made — CTR-012.
     */
    protected static function booted(): void
    {
        static::updating(function (TripDocument $document) {
            $wasDecided = TripDocumentStatus::isDecided(
                (string) $document->getOriginal('status')
            );

            if (! $wasDecided) {
                return;
            }

            foreach (['file_path', 'file_name', 'file_hash', 'file_mime', 'file_size'] as $frozen) {
                if ($document->isDirty($frozen)) {
                    throw new RuntimeException(
                        'A document is immutable once verified or rejected (CTR-012). '
                        .'File a replacement instead of altering the evidence.'
                    );
                }
            }
        });
    }

    /* ── Relations ────────────────────────────────────────────────────── */

    public function trip(): BelongsTo
    {
        return $this->belongsTo(TransportTrip::class, 'trip_id');
    }

    /* ── Scopes. None filters by tenant; they compose AFTER forTenant(). ── */

    public function scopeForTrip(Builder $query, int $tripId): Builder
    {
        return $query->where('trip_id', $tripId);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('document_type', $type);
    }

    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /** Documents that satisfy STT-008's "POD valid" guard. */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->whereIn('status', TripDocumentStatus::SATISFIES_BILLING_GATE);
    }

    /* ── Derived ──────────────────────────────────────────────────────── */

    public function isVerified(): bool
    {
        return $this->status === TripDocumentStatus::VERIFIED;
    }

    public function isDecided(): bool
    {
        return TripDocumentStatus::isDecided((string) $this->status);
    }

    public function getStatusLabelAttribute(): string
    {
        return TripDocumentStatus::label((string) $this->status);
    }
}
