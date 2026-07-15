<?php

namespace App\Models\Helpdesk;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KbArticle extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'kb_articles';

    protected $fillable = [
        'tenant_id', 'category_id', 'subcategory_id', 'department_id', 'title', 'excerpt',
        'content', 'is_published', 'public_slug', 'published_at', 'tags',
        'thumbs_up', 'thumbs_down',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'published_at' => 'datetime',
        'thumbs_up'    => 'integer',
        'thumbs_down'  => 'integer',
        'tags'         => 'array',
    ];

    /* ── Scopes ─────────────────────────────────────────────────── */
    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    /* ── Relationships ──────────────────────────────────────────── */
    public function category()
    {
        return $this->belongsTo(KbCategory::class, 'category_id');
    }

    public function subcategory()
    {
        return $this->belongsTo(KbSubcategory::class, 'subcategory_id');
    }

    public function department()
    {
        return $this->belongsTo(TicketDepartment::class, 'department_id');
    }
}
