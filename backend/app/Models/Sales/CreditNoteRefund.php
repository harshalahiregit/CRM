<?php

namespace App\Models\Sales;

use Illuminate\Database\Eloquent\Model;

class CreditNoteRefund extends Model
{
    protected $table = 'credit_note_refunds';

    protected $fillable = [
        'credit_note_id', 'amount', 'mode', 'transaction_id', 'note', 'date', 'created_by',
    ];

    protected $casts = ['date' => 'date', 'amount' => 'decimal:2'];
}
