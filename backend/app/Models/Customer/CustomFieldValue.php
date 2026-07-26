<?php

namespace App\Models\Customer;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CustomFieldValue extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'field_id', 'rel_id', 'field_to', 'value'];

    public function field()
    {
        return $this->belongsTo(CustomField::class, 'field_id');
    }
}
