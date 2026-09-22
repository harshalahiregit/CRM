<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POSH cases, who may read them, and a record of every time one was read.
 *
 * The access list is hr_posh_case_members and nothing else. Not a permission,
 * not a data scope, not the HR queue, not committee membership, and not being
 * an administrator. A harassment complaint may name any of those people, which
 * is exactly why none of them is a way in.
 *
 * MEMBERSHIP IS A HISTORY, not a state. Removing somebody sets removed_at on
 * their period; adding them back opens a new one. So there is deliberately NO
 * unique (case_id, user_id) — that constraint would force a re-admission to
 * overwrite the earlier period, erasing the record of who could read the file
 * and when. In a harassment case that is the least acceptable thing to lose.
 * "At most one ACTIVE period" is enforced in code instead, the way
 * HrEmployeeShift already handles the same shape.
 *
 * committee_id carries NO foreign key, deliberately. It is a snapshot of which
 * committee the case belongs to, in the same spirit as the department string
 * on a clearance item: a committee edited or removed later must not cascade
 * into, or block, a case already open. Tenancy is enforced in the resolver.
 *
 * hr_posh_case_reads is append-only and has no updated_at, because nothing
 * updates a read. It is a separate table from audit_logs on purpose — every
 * existing audit browser queries audit_logs, and a POSH read must not appear
 * in any of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_posh_cases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();

            $table->string('reference', 30);
            $table->unsignedBigInteger('committee_id');

            // An employee complainant or a pseudonymous one. Either way the
            // complainant is never a case member — their surface is separate.
            $table->string('complainant_type', 20)->default('employee');
            $table->unsignedBigInteger('complainant_employee_id')->nullable();
            $table->string('complainant_label', 150)->nullable();

            // The respondent is case DATA. They are not a member, and no
            // respondent-facing surface exists: what they may see is a legal
            // question nobody has answered yet, and guessing would be worse
            // than leaving it out.
            $table->unsignedBigInteger('respondent_employee_id')->nullable();
            $table->string('respondent_label', 150)->nullable();

            $table->dateTime('incident_at')->nullable();
            $table->string('incident_place', 200)->nullable();
            $table->text('narrative');

            $table->string('status', 30)->default('received');
            $table->string('outcome', 20)->nullable();
            $table->timestamp('findings_published_at')->nullable();

            // Structure for a retention policy that does not exist yet. No
            // period is defaulted and no purge is built: what the law requires
            // is unverified, and an invented number would become permanent.
            $table->unsignedBigInteger('retention_policy_id')->nullable();
            $table->timestamp('anonymised_at')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'reference'], 'hr_posh_case_tenant_ref_uniq');
            $table->index(['tenant_id', 'status'], 'hr_posh_case_tenant_status_idx');
            $table->index(['tenant_id', 'committee_id'], 'hr_posh_case_tenant_cmt_idx');
        });

        Schema::create('hr_posh_case_members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('case_id');
            $table->unsignedBigInteger('user_id');

            // Copied from the committee role BY VALUE. The role may later be
            // renamed, deactivated or deleted; this case's record of what the
            // person sat as does not move.
            $table->string('role_key', 60);

            // committee_snapshot | reconstitution | manual
            $table->string('source', 30)->default('committee_snapshot');

            $table->unsignedBigInteger('added_by')->nullable();
            $table->text('added_reason')->nullable();
            $table->timestamp('added_at');

            // Null means active. Setting it ends the period and keeps it.
            $table->timestamp('removed_at')->nullable();
            $table->unsignedBigInteger('removed_by')->nullable();
            $table->text('removed_reason')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'case_id'], 'hr_posh_member_tenant_case_idx');
            $table->index(['case_id', 'user_id', 'removed_at'], 'hr_posh_member_case_user_idx');
        });

        Schema::create('hr_posh_case_reads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('case_id');

            // Null for an actor who is not a User — a token complainant, in a
            // later phase. actor_label is always written, so the trail is
            // never anonymous.
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label', 150);

            $table->string('surface', 40);
            $table->string('ip', 45)->nullable();
            $table->timestamp('read_at');

            $table->index(['tenant_id', 'case_id', 'read_at'], 'hr_posh_read_tenant_case_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_posh_case_reads');
        Schema::dropIfExists('hr_posh_case_members');
        Schema::dropIfExists('hr_posh_cases');
    }
};
