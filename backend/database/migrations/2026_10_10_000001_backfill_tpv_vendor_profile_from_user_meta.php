<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data repair: TPV registration collected website / industry (and now legal name,
 * PAN and address) but only ever wrote them to users.meta, so the vendor record
 * showed a dash for details the vendor had already given us.
 *
 * AuthService::registerTPV now writes them to the vendor at registration; this
 * heals the rows created before that. The meta JSON is the source, because it is
 * the verbatim record of what was typed on the form.
 *
 * Deliberately conservative and idempotent:
 *   - only fills a column that is currently NULL or empty
 *   - never overwrites a value an admin has already curated
 *   - skips a vendor with no linked user, or whose meta has nothing useful
 * Safe to re-run.
 */
return new class extends Migration
{
    /** vendor column => key in users.meta */
    private const MAP = [
        'website'    => 'website',
        'category'   => 'industry',   // the form's word for the vendor master's `category`
        'legal_name' => 'legal_name',
        'pan_number' => 'pan_number',
        'address'    => 'address',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('vendors') || ! Schema::hasTable('users')) {
            return;
        }

        $columns = array_filter(
            array_keys(self::MAP),
            fn (string $c) => Schema::hasColumn('vendors', $c)
        );

        if ($columns === []) {
            return;
        }

        // Chunked and portable: an UPDATE..JOIN over a JSON column differs between
        // MySQL and SQLite, and this table is small enough that per-row work is fine.
        DB::table('vendors')
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->chunkById(200, function ($vendors) use ($columns) {
                foreach ($vendors as $vendor) {
                    $meta = DB::table('users')->where('id', $vendor->user_id)->value('meta');

                    if (! $meta) {
                        continue;
                    }

                    $meta = is_array($meta) ? $meta : json_decode((string) $meta, true);

                    if (! is_array($meta)) {
                        continue;   // unreadable meta is not worth guessing at
                    }

                    $patch = [];

                    foreach ($columns as $column) {
                        $current = $vendor->{$column} ?? null;

                        // Only ever fills a gap.
                        if ($current !== null && trim((string) $current) !== '') {
                            continue;
                        }

                        $value = $meta[self::MAP[$column]] ?? null;

                        if ($value === null || trim((string) $value) === '') {
                            continue;
                        }

                        $patch[$column] = $column === 'pan_number'
                            ? strtoupper(trim((string) $value))
                            : trim((string) $value);
                    }

                    if ($patch !== []) {
                        DB::table('vendors')->where('id', $vendor->id)->update($patch);
                    }
                }
            });
    }

    public function down(): void
    {
        // Irreversible by design: the blanks were the defect, not a state worth
        // restoring, and we cannot tell a backfilled value from a hand-entered one.
    }
};
