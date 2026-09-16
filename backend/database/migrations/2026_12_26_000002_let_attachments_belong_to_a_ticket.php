<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An attachment belongs to a ticket. Sometimes it also belongs to a reply.
 *
 * `ticket_attachments.reply_id` was NOT NULL, which made a reply the only way a
 * file could exist — so the person raising a ticket could not attach the
 * screenshot that explains it. The only workaround was to raise the ticket and
 * immediately reply to yourself, which puts a staff message at the top of the
 * thread and, worse, stamps first_responded_at: the SLA clock would show the
 * ticket answered before anyone had read it.
 *
 * So: `ticket_id` is the owner, and `reply_id` becomes optional — set when the
 * file arrived with a particular message, null when it arrived with the ticket
 * itself. Existing rows are backfilled from their reply, so nothing is orphaned.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ticket_attachments', 'ticket_id')) {
            Schema::table('ticket_attachments', function (Blueprint $table) {
                $table->unsignedBigInteger('ticket_id')->nullable()->after('tenant_id')->index();
            });
        }

        // Every existing attachment reached its ticket through its reply.
        DB::statement('
            UPDATE ticket_attachments
               SET ticket_id = (
                   SELECT ticket_id FROM ticket_replies WHERE ticket_replies.id = ticket_attachments.reply_id
               )
             WHERE ticket_id IS NULL
        ');

        // SQLite cannot relax a NOT NULL in place; the table is rebuilt instead.
        // Doctrine's change() is not available here, so this is done by hand and
        // guarded so it runs once.
        if ($this->replyIdIsRequired()) {
            Schema::create('ticket_attachments_new', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('ticket_id')->nullable()->index();
                $table->unsignedBigInteger('reply_id')->nullable()->index();
                $table->string('file_path');
                $table->string('file_name');
                $table->timestamps();
            });

            DB::statement('
                INSERT INTO ticket_attachments_new (id, tenant_id, ticket_id, reply_id, file_path, file_name, created_at, updated_at)
                SELECT id, tenant_id, ticket_id, reply_id, file_path, file_name, created_at, updated_at FROM ticket_attachments
            ');

            Schema::drop('ticket_attachments');
            Schema::rename('ticket_attachments_new', 'ticket_attachments');
        }
    }

    /** True while the column still refuses nulls. */
    private function replyIdIsRequired(): bool
    {
        if (DB::getDriverName() !== 'sqlite') {
            // MySQL/Postgres: relax it directly.
            try {
                DB::statement('ALTER TABLE ticket_attachments MODIFY reply_id BIGINT UNSIGNED NULL');
            } catch (\Throwable $e) {
                // Postgres spelling.
                try {
                    DB::statement('ALTER TABLE ticket_attachments ALTER COLUMN reply_id DROP NOT NULL');
                } catch (\Throwable $e2) {
                    // Already nullable, or a driver that needs no change.
                }
            }

            return false;
        }

        foreach (DB::select('PRAGMA table_info(ticket_attachments)') as $col) {
            if ($col->name === 'reply_id') {
                return (bool) $col->notnull;
            }
        }

        return false;
    }

    public function down(): void
    {
        if (Schema::hasColumn('ticket_attachments', 'ticket_id')) {
            Schema::table('ticket_attachments', function (Blueprint $table) {
                $table->dropColumn('ticket_id');
            });
        }
    }
};
