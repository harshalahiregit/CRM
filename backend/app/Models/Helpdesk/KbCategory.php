<?php

namespace App\Models\Helpdesk;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KbCategory extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'kb_categories';

    protected $fillable = [
        'tenant_id', 'name', 'slug',
    ];

    /* ── Relationships ──────────────────────────────────────────── */
    public function articles()
    {
        return $this->hasMany(KbArticle::class, 'category_id');
    }
}
