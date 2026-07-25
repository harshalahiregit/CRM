<?php

namespace App\Models\Accounts;

use App\Exceptions\BusinessException;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A physical chequebook: a contiguous run of pre-numbered leaves the bank
 * issued against one company bank account. Leaves are consumed strictly in
 * order via {@see allocateNext()}, which is the single source of the "next
 * available cheque number" the issuance form fills in automatically.
 */
class Chequebook extends Model
{
    use SoftDeletes, BelongsToTenant;

    protected $table = 'acc_chequebooks';

    protected $fillable = [
        'tenant_id', 'bank_account_id', 'name', 'prefix',
        'start_no', 'end_no', 'next_no', 'digits', 'total_leaves',
        'status', 'notes', 'created_by',
    ];

    protected $casts = [
        'start_no'     => 'integer',
        'end_no'       => 'integer',
        'next_no'      => 'integer',
        'digits'       => 'integer',
        'total_leaves' => 'integer',
    ];

    protected $appends = ['leaves_used', 'leaves_available', 'next_cheque_no'];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function cheques(): HasMany
    {
        return $this->hasMany(Cheque::class);
    }

    // ── Derived inventory metrics ──────────────────────────────────────────
    public function getLeavesUsedAttribute(): int
    {
        return max(0, $this->next_no - $this->start_no);
    }

    public function getLeavesAvailableAttribute(): int
    {
        if ($this->status !== 'active') {
            return 0;
        }
        return max(0, $this->end_no - $this->next_no + 1);
    }

    /** The number the NEXT issued cheque will carry, formatted for humans. */
    public function getNextChequeNoAttribute(): ?string
    {
        if ($this->status !== 'active' || $this->next_no > $this->end_no) {
            return null;
        }
        return $this->format($this->next_no);
    }

    public function format(int $serial): string
    {
        return ($this->prefix ?? '').str_pad((string) $serial, $this->digits, '0', STR_PAD_LEFT);
    }

    /**
     * Consume and return the next leaf's formatted number, advancing the
     * pointer atomically. Must run inside a DB transaction (the caller — a
     * cheque issuance — already opens one) so two concurrent issuances can
     * never draw the same leaf. Auto-marks the book exhausted on the last leaf.
     */
    public function allocateNext(): string
    {
        $book = static::whereKey($this->getKey())->lockForUpdate()->first();

        if ($book->status !== 'active') {
            throw new BusinessException("Chequebook \"{$book->name}\" is {$book->status} — no leaves can be issued from it.");
        }
        if ($book->next_no > $book->end_no) {
            throw new BusinessException("Chequebook \"{$book->name}\" has no leaves left.");
        }

        $number = $book->format($book->next_no);
        $book->next_no += 1;
        if ($book->next_no > $book->end_no) {
            $book->status = 'exhausted';
        }
        $book->save();

        // keep the in-memory instance consistent for the rest of the request
        $this->next_no = $book->next_no;
        $this->status = $book->status;

        return $number;
    }
}
