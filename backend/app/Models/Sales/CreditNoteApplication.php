<?php

namespace App\Models\Sales;

use Illuminate\Database\Eloquent\Model;

class CreditNoteApplication extends Model
{
    protected $table = 'credit_note_applications';

    protected $fillable = [
        'credit_note_id', 'invoice_id', 'amount', 'date', 'created_by',
    ];

    protected $casts = ['date' => 'date', 'amount' => 'decimal:2'];
}
