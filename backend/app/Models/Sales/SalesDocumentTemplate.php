<?php

namespace App\Models\Sales;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A reusable invoice / estimate / credit-note setup: line items plus the
 * document defaults (terms, notes, discount, currency).
 *
 * Line items hang off the shared polymorphic `sales_line_items` table, so
 * applying a template is a straight copy into the new document.
 */
class SalesDocumentTemplate extends Model
{
    use HasFactory, BelongsToTenant, SoftDeletes;

    /** The document types a template can be built for. */
    public const TYPES = ['invoice', 'estimate', 'proposal'];

    protected $fillable = [
        'tenant_id', 'doc_type', 'name', 'description',
        'terms', 'adminnote', 'clientnote', 'currency',
        'discount_type', 'discount_mode', 'discount_value',
        'sort_order', 'created_by',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
    ];

    public function lineItems()
    {
        return $this->morphMany(SalesLineItem::class, 'lineable')->orderBy('sort_order');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOfType($query, ?string $docType)
    {
        return $docType ? $query->where('doc_type', $docType) : $query;
    }
}
