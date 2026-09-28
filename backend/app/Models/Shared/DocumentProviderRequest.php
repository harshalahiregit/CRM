<?php

namespace App\Models\Shared;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One lead handed from a vendor to a compliance agency.
 *
 * Shared by both engines — the record is about the handover, not about which
 * vendor master the requester came from, so `vendor_kind` + `vendor_id` carry
 * that rather than two nullable relations. See the migration for why.
 */
class DocumentProviderRequest extends Model
{
    use BelongsToTenant;

    public const KIND_TPV = 'tpv';
    public const KIND_PURCHASE = 'purchase';

    /** Raised, but no address was configured for that provider yet. */
    public const QUEUED = 'Queued';
    public const SENT = 'Sent';
    public const FAILED = 'Failed';

    protected $fillable = [
        'tenant_id', 'vendor_kind', 'vendor_id',
        'provider_id', 'provider_name', 'provider_email',
        'document_type', 'document_label',
        'contact_name', 'contact_email', 'contact_mobile', 'company_name', 'notes',
        'consented_at', 'status', 'sent_at', 'failure_reason',
    ];

    protected $casts = [
        'consented_at' => 'datetime',
        'sent_at'      => 'datetime',
    ];
}
