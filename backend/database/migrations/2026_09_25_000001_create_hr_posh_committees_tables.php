<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The POSH committee, its roles and its members.
 *
 * Configuration only. No case exists yet, nothing references a committee, and
 * nothing here grants access to anything — can_manage_case is stored and
 * validated, and will only mean something in a later phase, and then only
 * alongside explicit case membership.
 *
 * NO STATUTORY MINIMUMS. Composition and quorum are whatever a workspace
 * configures; a one-person committee with a quorum of one is valid as far as
 * this schema is concerned. What the law requires is not encoded here and must
 * not be guessed at.
 *
 * Roles are per committee rather than global. A workspace defines its own
 * closed vocabulary — presiding officer, internal member, external member — and
 * that vocabulary belongs to the committee that uses it. The existing
 * staff_roles system is untouched: members are ordinary users, and no second
 * global role concept is introduced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_posh_committees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();

            $table->string('name', 150);

            // all_members | n_of_m. quorum_required is null for all_members,
            // because "everybody" is not a number and storing one would invite
            // the two to disagree.
            $table->string('quorum_mode', 20)->default('all_members');
            $table->unsignedSmallInteger('quorum_required')->nullable();

            // Inactive until deliberately switched on, so a half-configured
            // committee can never be used. The invariants below are enforced
            // continuously only while a committee is active.
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('sort_order')->default(0);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'name'], 'hr_posh_cmt_tenant_name_uniq');
            $table->index(['tenant_id', 'is_active'], 'hr_posh_cmt_tenant_active_idx');
        });

        Schema::create('hr_posh_committee_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('committee_id');

            $table->string('key', 60);
            $table->string('label', 150);

            /*
             | Administrative authority over a case, and nothing more: opening
             | and closing an inquiry round, initiating an escalation, and
             | publishing a finding that has already been reached.
             |
             | It can never override a quorum, break a tie, overturn another
             | member's decision, or reach a case the holder is not a member of.
             | Default off, and an active committee must have at least one role
             | carrying it — otherwise nobody could ever open an inquiry.
             */
            $table->boolean('can_manage_case')->default(false);

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['committee_id', 'key'], 'hr_posh_role_cmt_key_uniq');
            $table->index(['tenant_id', 'committee_id'], 'hr_posh_role_tenant_cmt_idx');
        });

        Schema::create('hr_posh_committee_members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('committee_id');
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('user_id');

            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            // One seat each. Somebody holding two roles would count twice
            // towards a quorum, which is a way of quietly lowering it.
            $table->unique(['committee_id', 'user_id'], 'hr_posh_member_cmt_user_uniq');
            $table->index(['tenant_id', 'committee_id'], 'hr_posh_member_tenant_cmt_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_posh_committee_members');
        Schema::dropIfExists('hr_posh_committee_roles');
        Schema::dropIfExists('hr_posh_committees');
    }
};
