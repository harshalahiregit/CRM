<?php

namespace App\Models\Vendor;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Support\Vendor\VendorDocumentStatus as Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Statutory/legal document attached to a vendor. One model covers both the TPV
 * onboarding doc set (GST/PF/WCP/BOCW/CLR/MLWF/Udyam…) and Purchase's contractor
 * paperwork — legacy had two overlapping registries; they are reconciled here.
 */
class VendorDocument extends Model
{
    use Auditable, SoftDeletes, BelongsToTenant;

    protected $table = 'vendor_documents';

    protected $fillable = [
        'tenant_id','vendor_id','type','file_path','original_name','mime','size',
        'status','remarks','reviewed_by','reviewed_at','verified_by','verified_at','expires_at','renewal_due_at',
    ];

    protected $casts = [
        'reviewed_at'    => 'datetime',
        'verified_at'    => 'datetime',
        'expires_at'     => 'date',
        'renewal_due_at' => 'date',
        'size'           => 'integer',
    ];

    protected $appends = ['status_label', 'type_label'];

    /** Standard-vendor doc set (full statutory requirement). */
    public const STANDARD_SET = [
        'company_registration','pan','insurance_wcp','gst','pf','esic',
        'bocw','clr','mlwf','mscb','udyam',
    ];

    /** Temporary-vendor doc set (reduced, time-boxed engagement). */
    public const TEMPORARY_SET = [
        'insurance_wcp','gst','loi_wo_po',
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
     * so a versioning failure can never break the existing upload/review flow.
     */
    protected static function booted(): void
    {
        static::created(function (VendorDocument $doc) {
            if ($doc->file_path) {
                self::captureVersionSafely($doc);
            }
        });

        static::updated(function (VendorDocument $doc) {
            if ($doc->wasChanged('file_path') && $doc->file_path) {
                self::captureVersionSafely($doc);
            }
        });
    }

    private static function captureVersionSafely(VendorDocument $doc): void
    {
        try {
            app(\App\Services\Vendor\VendorDocumentVersionService::class)->capture($doc);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::channel('tpv')->warning('Document version capture failed', [
                'document_id' => $doc->id, 'error' => $e->getMessage(),
            ]);
        }
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function versions()
    {
        return $this->hasMany(VendorDocumentVersion::class, 'vendor_document_id');
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
