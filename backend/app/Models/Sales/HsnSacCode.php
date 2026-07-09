<?php

namespace App\Models\Sales;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HsnSacCode extends Model
{
    use HasFactory;

    protected $table = 'hsn_sac_codes';

    protected $fillable = ['code', 'description', 'gst_rate', 'type'];

    protected $casts = [
        'gst_rate' => 'decimal:2',
    ];
}
