<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reconciliation issues an admin has looked at and decided are fine.
 *
 * The directory panel reports pairs that disagree — an employee whose login is
 * typed as a portal account, an employee with no login at all. Some of those are
 * correct in a particular workspace and will never be "fixed", and a panel that
 * keeps shouting about them is one people stop reading. That costs more than the
 * issue it was reporting, because the real problems arrive in the same list.
 *
 * WHY A TABLE rather than a tenant setting. SettingsService only stores values
 * declared in SettingRegistry, casts them to scalars, and surfaces the hr group
 * in the HR Settings screen — none of which fits a growing list of internal keys.
 * More importantly, dismissing an identity problem is a decision somebody made,
 * and PART 8 of the brief asks for these actions to be auditable: this records
 * who did it and when, which a settings blob cannot.
 *
 * `issue_key` is derived from the problem (its type and the employee), not stored
 * with it. So a dismissal stops applying the moment the underlying facts change —
 * fix the mismatch and re-break it later, and the panel reports it again rather
 * than staying quiet because somebody clicked Dismiss a year ago.
 *
 * Purely additive: a new table, nothing existing is altered, and the feature
 * degrades to "nothing is dismissed" if the table is absent. Safe to roll back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_directory_dismissals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('issue_key', 191);
            $table->unsignedBigInteger('dismissed_by')->nullable();
            $table->timestamps();

            // One dismissal per issue per workspace; dismissing twice is a no-op
            // rather than a second row.
            $table->unique(['tenant_id', 'issue_key']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_directory_dismissals');
    }
};
