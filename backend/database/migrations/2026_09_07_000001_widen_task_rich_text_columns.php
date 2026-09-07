<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Room for a comment that contains a picture.
 *
 * Task comments and descriptions are authored in a rich editor that embeds
 * images inline, as base64 inside the HTML — the same way the rest of the app's
 * rich text works. Both columns were TEXT, which on MySQL holds 65,535 bytes.
 * A single compressed screenshot is comfortably more than that, so a comment
 * with a picture in it either failed outright or was silently truncated to
 * broken markup on the way in.
 *
 * LONGTEXT matches what the editor can actually produce. The images themselves
 * are already downscaled and re-encoded client-side before they are embedded
 * (see mediaCompress.js), so this is headroom, not an invitation.
 */
return new class extends Migration
{
    /** table => column */
    private const COLUMNS = [
        'task_comments' => 'content',
        'tasks' => 'description',
    ];

    public function up(): void
    {
        // SQLite has one TEXT type with no length limit, so there is nothing to
        // widen there and no MODIFY syntax to do it with.
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        foreach (self::COLUMNS as $table => $column) {
            if (Schema::hasColumn($table, $column)) {
                DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` LONGTEXT");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        foreach (self::COLUMNS as $table => $column) {
            if (Schema::hasColumn($table, $column)) {
                // tasks.description is nullable; task_comments.content is not.
                $null = $column === 'description' ? 'NULL' : 'NOT NULL';
                DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` TEXT {$null}");
            }
        }
    }
};
