<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Take the candidate's onboarding link out of plaintext.
 *
 * hr_onboarding.access_token has held the raw credential since the candidate
 * portal shipped: 48 characters, stored as typed, with no expiry and no way to
 * revoke it. That link accepts personal details, education, experience, bank
 * details and identity documents, so anybody who could read this column held a
 * permanent key to every candidate's file.
 *
 * NO DUAL-READ WINDOW, AND THAT IS THE SAFER CHOICE. The obvious compatibility
 * strategy is to keep reading plaintext for a while and upgrade rows as they
 * are used. It has two costs: the plaintext stays readable for the whole
 * window, including for links nobody ever opens, and the lookup grows a second
 * path that is itself a bypass if it is ever reached by mistake.
 *
 * Neither is necessary, because the hash is DERIVABLE FROM WHAT IS ALREADY
 * STORED. Every existing token is hashed here and the plaintext column is
 * emptied in the same migration. The candidate's link does not change — the
 * raw value they hold still hashes to the row — so nothing they were sent
 * stops working, and the plaintext is gone at deploy rather than weeks later.
 *
 * EXPIRY IS DATED FROM THIS MIGRATION, not from when the link was sent. Dating
 * it from invited_at would expire every link older than the window the moment
 * this runs, which is exactly the silent invalidation that must not happen. So
 * existing links get one bounded grace period from deploy and then expire like
 * any other. They are not made immortal; they are given a deadline they did
 * not have.
 *
 * access_token is KEPT as a column and emptied rather than dropped. Dropping it
 * is destructive and irreversible on a table holding live candidate records,
 * and nothing reads it after this. It is legacy and NOT authoritative:
 * token_hash is the credential.
 */
return new class extends Migration
{
    /**
     * The grace period existing links get, in days.
     *
     * Fixed here rather than read from the tenant setting: a migration runs
     * once, before anybody has had the chance to configure anything, and it
     * must behave identically on every workspace. New tokens issued afterwards
     * use the configurable TTL.
     */
    private const LEGACY_GRACE_DAYS = 30;

    public function up(): void
    {
        if (! Schema::hasTable('hr_onboarding')) {
            return;
        }

        Schema::table('hr_onboarding', function (Blueprint $table) {
            // sha256 hex of the raw token. Unique, because two candidates
            // sharing a credential is not a state worth being able to reach.
            $table->char('token_hash', 64)->nullable()->unique()->after('access_token');

            $table->timestamp('token_issued_at')->nullable()->after('token_hash');
            $table->timestamp('token_expires_at')->nullable()->after('token_issued_at');
            $table->timestamp('token_revoked_at')->nullable()->after('token_expires_at');
        });

        $this->rehashExistingTokens();
    }

    /**
     * Hash what is already there, then empty the plaintext.
     *
     * Chunked and keyed by id so a large table does not load at once, and
     * written row by row because each hash is different — there is no bulk
     * update that could do this.
     */
    private function rehashExistingTokens(): void
    {
        $expiresAt = now()->addDays(self::LEGACY_GRACE_DAYS);

        DB::table('hr_onboarding')
            ->select('id', 'access_token', 'invited_at', 'created_at')
            ->whereNotNull('access_token')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($expiresAt) {
                foreach ($rows as $row) {
                    $raw = (string) $row->access_token;

                    if ($raw === '') {
                        continue;
                    }

                    DB::table('hr_onboarding')->where('id', $row->id)->update([
                        'token_hash'   => hash('sha256', $raw),
                        // When the link actually went out, which is worth
                        // keeping even though expiry is not dated from it.
                        'token_issued_at'  => $row->invited_at ?: $row->created_at,
                        'token_expires_at' => $expiresAt,
                        // The whole point.
                        'access_token'     => null,
                    ]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('hr_onboarding')) {
            return;
        }

        // The plaintext cannot be restored — a hash does not reverse — so this
        // only removes the columns that were added. Rolling back leaves the
        // portal unable to resolve any token, which is the honest outcome of
        // undoing a migration whose entire purpose was to discard a secret.
        Schema::table('hr_onboarding', function (Blueprint $table) {
            $table->dropUnique(['token_hash']);
            $table->dropColumn(['token_hash', 'token_issued_at', 'token_expires_at', 'token_revoked_at']);
        });
    }
};
