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
        'tenant_id', 'category_id', 'title', 'content', 'thumbs_up', 'thumbs_down',
    ];

    protected $casts = [
        'thumbs_up'   => 'integer',
        'thumbs_down' => 'integer',
    ];

    /* ── Relationships ──────────────────────────────────────────── */
    public function category()
    {
        return $this->belongsTo(KbCategory::class, 'category_id');
    }
}
