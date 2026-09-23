<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Take the candidate's offer link out of plaintext.
 *
 * hr_offers.access_token has held the raw credential since the Offer Portal
 * shipped: 48 characters, stored as typed, unique-indexed, readable by anybody
 * who can read the table. That link shows the offered CTC and the full salary
 * breakup, and it is the thing that ACCEPTS the offer — a signature, an IP and
 * a device fingerprint are recorded against whoever holds it.
 *
 * NO COMPATIBILITY WINDOW IS NEEDED, AND THAT IS NOT A SHORTCUT.
 *
 * The brief anticipated that existing plaintext tokens "cannot be converted
 * into hashes without retaining the original raw token", and asked for a
 * staged migration with a temporary fallback if that were so. It is not so,
 * and the distinction matters enough to state plainly: the raw token IS what
 * is currently stored, so this migration can read it, hash it, and discard the
 * plaintext in one pass. The value in the candidate's inbox is unchanged, and
 * it still hashes to its row — so NOT ONE ACTIVE OFFER LINK STOPS WORKING.
 *
 * That makes the staged strategy strictly worse here. A compatibility window
 * would keep every token readable for its whole duration, including tokens for
 * offers nobody ever opens, and would leave a second resolution path in the
 * code that is a plaintext bypass the moment it is reached by mistake. There is
 * no fallback resolver in this change because there is nothing for one to do.
 *
 * NO EXPIRY COLUMN, DELIBERATELY. The onboarding equivalent of this migration
 * added token_expires_at, because that portal had no expiry of its own. An
 * offer does: hr_offers.validity_date, surfaced as the Expired status. Adding a
 * second clock would mean a link could die while the offer it opens is still
 * valid, which changes what an offer's validity DATE MEANS. The token is live
 * until it is superseded or revoked; whether the offer can still be accepted
 * stays the business rule it already was.
 *
 * access_token is EMPTIED, NOT DROPPED. Dropping a column is destructive and
 * irreversible on a table holding live offers, and nothing reads it after this.
 * It is legacy and it is NOT authoritative: token_hash is the credential.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_offers')) {
            return;
        }

        // Re-runnable: a partially applied deploy adds only what is missing,
        // and the backfill below skips rows that have already been converted.
        if (! Schema::hasColumn('hr_offers', 'token_hash')) {
            Schema::table('hr_offers', function (Blueprint $table) {
                // sha256 hex of the raw token. Unique, because two candidates
                // sharing an offer credential is not a state worth reaching.
                $table->char('token_hash', 64)->nullable()->unique()->after('access_token');

                // When this credential was minted, and when it was deliberately
                // killed. There is no expires_at — see the class note.
                $table->timestamp('token_issued_at')->nullable()->after('token_hash');
                $table->timestamp('token_revoked_at')->nullable()->after('token_issued_at');
            });
        }

        $this->rehashExistingTokens();
    }

    /**
     * Hash what is already stored, then empty the plaintext.
     *
     * Chunked by id so a large table is never loaded at once, and written row
     * by row because every hash differs — there is no bulk update that could
     * do this.
     *
     * IDEMPOTENT. The filter is `access_token IS NOT NULL`, and each converted
     * row is nulled in the same write, so a second run finds nothing and a run
     * interrupted halfway resumes exactly where it stopped.
     */
    private function rehashExistingTokens(): void
    {
        DB::table('hr_offers')
            ->select('id', 'access_token', 'generated_at', 'sent_at', 'created_at')
            ->whereNotNull('access_token')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $raw = (string) $row->access_token;

                    if ($raw === '') {
                        continue;
                    }

                    DB::table('hr_offers')->where('id', $row->id)->update([
                        'token_hash' => hash('sha256', $raw),
                        // Best available truth about when the candidate got it:
                        // the offer was minted at generation and delivered at
                        // send. Recorded for the audit trail only — nothing
                        // about liveness is computed from it.
                        'token_issued_at' => $row->generated_at ?: ($row->sent_at ?: $row->created_at),
                        // The whole point of the migration.
                        'access_token'    => null,
                    ]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('hr_offers') || ! Schema::hasColumn('hr_offers', 'token_hash')) {
            return;
        }

        // The plaintext cannot come back — a hash does not reverse — so this
        // drops only the columns that were added. Rolling back leaves the
        // portal unable to resolve any token, which is the honest outcome of
        // undoing a migration whose entire purpose was to discard a secret.
        Schema::table('hr_offers', function (Blueprint $table) {
            $table->dropUnique(['token_hash']);
            $table->dropColumn(['token_hash', 'token_issued_at', 'token_revoked_at']);
        });
    }
};
