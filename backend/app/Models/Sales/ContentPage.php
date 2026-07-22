<?php

namespace App\Models\Sales;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContentPage extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'content_pages';

    protected $fillable = [
        'tenant_id', 'pageable_type', 'pageable_id',
        'title', 'content', 'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function pageable()
    {
        return $this->morphTo();
    }
}
