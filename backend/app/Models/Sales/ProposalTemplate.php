<?php

namespace App\Models\Sales;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProposalTemplate extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'name', 'description', 'category', 'content', 'terms',
        'thumbnail_url', 'is_default', 'sort_order', 'created_by',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function proposals()
    {
        return $this->hasMany(Proposal::class, 'template_id');
    }

    public function pages()
    {
        return $this->morphMany(ContentPage::class, 'pageable')->orderBy('sort_order');
    }
}
