<?php

namespace App\Models\Sales;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SalesLineItem extends Model
{
    use HasFactory;

    protected $table = 'sales_line_items';

    protected $fillable = [
        'lineable_type', 'lineable_id', 'item_id',
        'item_name', 'description', 'qty', 'unit',
        'rate', 'tax', 'taxes', 'discount', 'discount_mode', 'total', 'sort_order',
        'hsn_sac_code',
    ];

    protected $casts = [
        'qty'      => 'decimal:2',
        'rate'     => 'decimal:2',
        'tax'      => 'decimal:2',
        'taxes'    => 'array',
        'discount' => 'decimal:2',
        'total'    => 'decimal:2',
    ];

    public function lineable()
    {
        return $this->morphTo();
    }

    public function item()
    {
        return $this->belongsTo(SalesItem::class, 'item_id');
    }

    /**
     * Normalise a submitted line's tax selection.
     *
     * A line may carry several named taxes (CGST 9% + SGST 9%). The summed
     * rate is mirrored into `tax` so every existing totals calculation,
     * report and PDF keeps working untouched — `taxes` only adds the
     * per-name breakdown. Lines submitted the old way (a bare `tax`
     * percentage) are passed through with taxes = null.
     *
     * @return array{taxes: ?array<int, array{name: string, rate: float}>, tax: float}
     */
    public static function normalizeTaxes(array $item): array
    {
        $submitted = $item['taxes'] ?? null;
        $fallback  = ['taxes' => null, 'tax' => (float) ($item['tax'] ?? 0)];

        if (! is_array($submitted) || $submitted === []) {
            return $fallback;
        }

        $clean = [];
        foreach ($submitted as $tax) {
            $name = trim((string) ($tax['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $clean[] = ['name' => $name, 'rate' => round((float) ($tax['rate'] ?? 0), 2)];
        }

        if ($clean === []) {
            return $fallback;
        }

        return ['taxes' => $clean, 'tax' => round(array_sum(array_column($clean, 'rate')), 2)];
    }

    /** Resolved discount amount for a line (flat ₹ or % of the line value). */
    public static function discountAmount(array $data): float
    {
        $base = (float) ($data['qty'] ?? 0) * (float) ($data['rate'] ?? 0);
        $value = (float) ($data['discount'] ?? 0);
        $amount = ($data['discount_mode'] ?? 'fixed') === 'percent' ? $base * $value / 100 : $value;

        return round(min(max($amount, 0), max($base, 0)), 2);
    }

    /**
     * Compute line total: (qty * rate - discount) + tax%
     */
    public static function computeTotal(array $data): float
    {
        $base      = $data['qty'] * $data['rate'];
        $afterDis  = $base - ($data['discount'] ?? 0);
        $taxAmount = $afterDis * ($data['tax'] / 100);
        return round($afterDis + $taxAmount, 2);
    }
}
