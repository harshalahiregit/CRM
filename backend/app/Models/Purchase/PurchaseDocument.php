<?php

namespace App\Models\Purchase;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Models\Purchase\PurchaseVendor;
use App\Support\Purchase\PurchaseDocumentStatus as Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Statutory/legal document attached to a Purchase vendor. Purchase-owned
 * (purchase_documents) — completely independent of the shared Vendor Master doc
 * store and of TPV. Keyed to the shared Vendor Master by purchase_vendor_id only.
 */
class PurchaseDocument extends Model
{
    use Auditable, SoftDeletes, BelongsToTenant;

    protected $table = 'purchase_documents';

    protected $fillable = [
        'tenant_id', 'purchase_vendor_id', 'type', 'file_path', 'original_name', 'mime', 'size',
        'status', 'remarks', 'reviewed_by', 'reviewed_at', 'expires_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'expires_at'  => 'date',
        'size'        => 'integer',
    ];

    protected $appends = ['status_label', 'type_label'];

    /** Standard-vendor doc set (full statutory requirement). */
    public const STANDARD_SET = [
        'company_registration', 'pan', 'insurance_wcp', 'gst', 'pf', 'esic',
        'bocw', 'clr', 'mlwf', 'mscb', 'udyam',
    ];

    /** Temporary-vendor doc set (reduced, time-boxed engagement). */
    public const TEMPORARY_SET = [
        'insurance_wcp', 'gst', 'loi_wo_po',
    ];

    /** Human-readable names for each statutory document type. */
    public const TYPE_LABELS = [
        'company_registration' => 'Company Registration',
        'pan'                  => 'PAN Card',
        'insurance_wcp'        => 'Insurance / WCP',
        'gst'                  => 'GST Certificate',
        'pf'                   => 'PF Registration',
        'esic'                 => 'ESIC Registration',
        'bocw'                 => 'BOCW Registration',
        'clr'                  => 'CLRA / Labour Licence',
        'mlwf'                 => 'MLWF',
        'mscb'                 => 'MSCB',
        'udyam'                => 'Udyam Certificate',
        'loi_wo_po'            => 'LOI / WO / PO',
    ];

    /**
     * Version capture is a side effect: whenever the file is created or changed
     * (upload / replace / resubmit / restore), snapshot an immutable copy. Wrapped
     * so a versioning failure can never break the upload/review flow.
     */
    protected static function booted(): void
    {
        static::created(function (PurchaseDocument $doc) {
            if ($doc->file_path) {
                self::captureVersionSafely($doc);
            }
        });

        static::updated(function (PurchaseDocument $doc) {
            if ($doc->wasChanged('file_path') && $doc->file_path) {
                self::captureVersionSafely($doc);
            }
        });
    }

    private static function captureVersionSafely(PurchaseDocument $doc): void
    {
        try {
            app(\App\Services\Purchase\PurchaseDocumentVersionService::class)->capture($doc);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::channel('purchase')->warning('Document version capture failed', [
                'document_id' => $doc->id, 'error' => $e->getMessage(),
            ]);
        }
    }

    public function vendor()
    {
        return $this->belongsTo(PurchaseVendor::class, 'purchase_vendor_id');
    }

    public function versions()
    {
        return $this->hasMany(PurchaseDocumentVersion::class, 'purchase_document_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /* ── Helpers ────────────────────────────────────────────────────────── */

    public function getStatusLabelAttribute(): string
    {
        return Status::label($this->status);
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPE_LABELS[$this->type] ?? ucwords(str_replace('_', ' ', (string) $this->type));
    }

    public function isApproved(): bool
    {
        return $this->status === Status::APPROVED;
    }

    /** Required doc types for a given vendor_type. */
    public static function requiredFor(string $vendorType): array
    {
        return $vendorType === 'temporary' ? self::TEMPORARY_SET : self::STANDARD_SET;
    }

    /** Human label for any doc type (required or extra). */
    public static function typeLabel(string $type): string
    {
        return self::TYPE_LABELS[$type] ?? ucwords(str_replace('_', ' ', $type));
    }
}
