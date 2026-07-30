<?php

namespace App\Models\Email;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant's CUSTOMISATION of an email template. Absence of a row means the tenant
 * uses the shipped template from EmailTemplateRegistry — which is what makes
 * "restore default" a simple, non-destructive delete.
 */
class EmailTemplate extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'email_templates';

    protected $fillable = [
        'tenant_id', 'key', 'category', 'name', 'subject',
        'body_html', 'body_text', 'description',
        'enabled', 'version', 'is_system', 'locale', 'updated_by',
    ];

    protected $casts = [
        'enabled'   => 'boolean',
        'is_system' => 'boolean',
        'version'   => 'integer',
    ];
}
