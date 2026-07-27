<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One immutable snapshot of a Purchase-vendor document's file. Created every time
 * the document's file changes (upload / replace / resubmit / restore). Never
 * deleted — the full history is kept permanently. Purchase-owned.
 */
class PurchaseDocumentVersion extends Model
{
    use BelongsToTenant;

    protected $table = 'purchase_document_versions';

    protected $fillable = [
        'tenant_id', 'purchase_document_id', 'version_no', 'file_path', 'original_name',
        'mime', 'size', 'status_at_capture', 'captured_by', 'is_current', 'restored_from_version_id',
    ];

    protected $casts = [
        'version_no' => 'integer',
        'size'       => 'integer',
        'is_current' => 'boolean',
    ];

    public function document()
    {
        return $this->belongsTo(PurchaseDocument::class, 'purchase_document_id');
    }
}
