<?php

namespace App\Models\Helpdesk;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KbSubcategory extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'kb_subcategories';

    protected $fillable = [
        'tenant_id', 'category_id', 'name', 'slug',
    ];

    /* ── Relationships ──────────────────────────────────────────── */
    public function category()
    {
        return $this->belongsTo(KbCategory::class, 'category_id');
    }

    public function articles()
    {
        return $this->hasMany(KbArticle::class, 'subcategory_id');
    }
}
