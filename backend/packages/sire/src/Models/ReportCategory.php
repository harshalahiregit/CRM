<?php

namespace Sire\Models;

use Sire\Models\Concerns\BelongsToSireTenant;
use Sire\Support\SireReleaseClass;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * SIRE — the per-tenant issue type master.
 *
 * Doubles as the release-content mapping: `release_class` says whether issues of
 * this type read as a bug, a change, an improvement, a security fix or a
 * performance fix in release notes, so a tenant configures that once rather than
 * per issue.
 */
class ReportCategory extends Model
{
    use HasFactory;

    /** The package ships its own factories; Laravel's default guess looks under App\. */
    protected static function newFactory(): \Sire\Database\Factories\ReportCategoryFactory
    {
        return \Sire\Database\Factories\ReportCategoryFactory::new();
    }
    use BelongsToSireTenant;
    use SoftDeletes;

    protected $table = 'sire_report_categories';

    protected $guarded = ['id'];

    protected $casts = [
        'field_schema'             => 'array',
        'requires_investigation'   => 'boolean',
        'requires_closure_approval' => 'boolean',
        'is_active'                => 'boolean',
        'sort_order'               => 'integer',
    ];

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class, 'category_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function releaseClassOrNull(): ?string
    {
        return SireReleaseClass::isValid($this->release_class) ? $this->release_class : null;
    }
}
