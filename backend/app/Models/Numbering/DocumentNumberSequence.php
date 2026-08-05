<?php

namespace App\Models\Numbering;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Runtime sequence state: the cursor for one (tenant, document type, period).
 * Written only by DatabaseDocumentNumberService, always inside a transaction with
 * a row lock. Rows are never deleted — a reset creates the next period's row.
 */
class DocumentNumberSequence extends Model
{
    use BelongsToTenant;

    protected $table = 'document_number_sequences';

    protected $fillable = ['tenant_id', 'document_type', 'period_key', 'current_sequence'];

    protected $casts = ['current_sequence' => 'integer'];
}
