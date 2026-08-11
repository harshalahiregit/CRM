<?php

namespace App\Support\Sql;

use Illuminate\Support\Facades\DB;

/**
 * Driver-portable date SQL.
 *
 * julianday() and strftime() are SQLite-only. Several HR reports were written
 * against the dev database and called them directly, so on MySQL they raised
 * "FUNCTION <db>.julianday does not exist" — meaning those screens could never
 * have worked in production, and the failure only surfaced when someone opened
 * one. HelpdeskService and ProjectInvoiceService already each carried their own
 * driver match; this centralises the same idea so the remaining call sites share
 * one implementation instead of five copies drifting apart.
 *
 * Column names are interpolated, so callers must pass literal identifiers —
 * never user input.
 */
final class SqlDate
{
    private static function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    /** ($end - $start) expressed in days. */
    public static function days(string $start, string $end): string
    {
        return match (self::driver()) {
            'mysql', 'mariadb' => "TIMESTAMPDIFF(SECOND, {$start}, {$end}) / 86400.0",
            'pgsql'            => "EXTRACT(EPOCH FROM ({$end} - {$start})) / 86400.0",
            default            => "(julianday({$end}) - julianday({$start}))",
        };
    }

    /** Four-digit year as an integer. */
    public static function year(string $col): string
    {
        return match (self::driver()) {
            'mysql', 'mariadb' => "YEAR({$col})",
            'pgsql'            => "EXTRACT(YEAR FROM {$col})::int",
            default            => "CAST(strftime('%Y', {$col}) AS INTEGER)",
        };
    }

    /** Month number 1-12 as an integer. */
    public static function month(string $col): string
    {
        return match (self::driver()) {
            'mysql', 'mariadb' => "MONTH({$col})",
            'pgsql'            => "EXTRACT(MONTH FROM {$col})::int",
            default            => "CAST(strftime('%m', {$col}) AS INTEGER)",
        };
    }

    /** 'YYYY-MM' bucket, for grouping a trend by month. */
    public static function yearMonth(string $col): string
    {
        return match (self::driver()) {
            'mysql', 'mariadb' => "DATE_FORMAT({$col}, '%Y-%m')",
            'pgsql'            => "to_char({$col}, 'YYYY-MM')",
            default            => "strftime('%Y-%m', {$col})",
        };
    }
}
