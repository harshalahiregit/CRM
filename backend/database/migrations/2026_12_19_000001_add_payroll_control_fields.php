<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-employee payroll switches every payroll system has, and this one did not.
 *
 * The statutory engine decides WHAT PF is; nothing decided WHETHER this person
 * has PF. Both are needed: a director above the ceiling who has opted out, a
 * consultant on contract, an apprentice — each is a normal case that the rules
 * alone cannot express, and without a switch the only way to exclude somebody is
 * to leave their UAN blank and hope every report notices.
 *
 * Defaults are chosen so that seeding this changes nobody's pay: applicability
 * defaults to TRUE, matching the behaviour before the column existed, where the
 * rule applied to everyone it resolved for.
 *
 * WHY EACH ONE EXISTS:
 *
 *   vpf_amount / vpf_percent
 *       The PF register HAS a VPF column and we had nowhere to put it. Voluntary
 *       PF is the employee contributing above the statutory 12%; it is their
 *       choice, expressed as either a flat amount or a percentage, never both.
 *
 *   restrict_pf_to_ceiling
 *       Per employee, because it genuinely varies: some staff contribute on the
 *       15,000 ceiling and others on full salary. It exists on the RULE already;
 *       this overrides it for one person, which is how the choice is actually made.
 *
 *   eps_applicable
 *       Separate from pf_applicable. An international worker has PF and no EPS;
 *       somebody who joined after 58 has PF and no EPS. The age rule handles the
 *       common case, this handles the rest.
 *
 *   is_disabled
 *       ESIC's wage ceiling is 25,000 for a person with disability against 42,000
 *       otherwise. Without this flag they are wrongly excluded above 25,000.
 *
 *   esic_dispensary
 *       ESIC registration asks for it (see ESIC.docx) and it appears on the IP's
 *       record. Nowhere to keep it meant looking it up on the portal each time.
 *
 *   pf_joining_date
 *       PF membership can start later than employment — a probationer enrolled on
 *       confirmation. joining_date is the wrong date to file with.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_employee_details')) {
            return;
        }

        Schema::table('hr_employee_details', function (Blueprint $t) {
            // ── Whether each statutory deduction applies at all ──
            $t->boolean('pf_applicable')->default(true)->after('uan_number');
            $t->boolean('eps_applicable')->default(true)->after('pf_applicable');
            $t->boolean('esic_applicable')->default(true)->after('eps_applicable');
            $t->boolean('pt_applicable')->default(true)->after('esic_applicable');
            $t->boolean('lwf_applicable')->default(true)->after('pt_applicable');
            $t->boolean('gratuity_applicable')->default(true)->after('lwf_applicable');

            // ── PF detail ──
            $t->date('pf_joining_date')->nullable()->after('pf_number');
            // Exactly one of these is used. A flat amount wins if both are set,
            // because that is the less surprising reading of "deduct 2000".
            $t->decimal('vpf_amount', 12, 2)->nullable()->after('pf_joining_date');
            $t->decimal('vpf_percent', 5, 2)->nullable()->after('vpf_amount');
            // Null = follow the rule. True/false = this person is different.
            $t->boolean('restrict_pf_to_ceiling')->nullable()->after('vpf_percent');

            // ── ESIC detail ──
            $t->string('esic_dispensary')->nullable()->after('esic_ip_number');
            $t->boolean('is_disabled')->default(false)->after('esic_dispensary');
        });

        // Branch belongs on the employee, not the detail: it is who they are and
        // where they report, and reports group by it.
        if (Schema::hasTable('hr_employees') && ! Schema::hasColumn('hr_employees', 'branch')) {
            Schema::table('hr_employees', function (Blueprint $t) {
                $t->string('branch', 120)->nullable()->after('location');
            });
        }

        // LWF has nowhere to land on a payroll run. Every other statutory
        // deduction has its own pair of columns; without these it would either be
        // invisible or hidden inside a total nobody can break down.
        if (Schema::hasTable('hr_payroll_records')) {
            Schema::table('hr_payroll_records', function (Blueprint $t) {
                $t->decimal('lwf_employee', 12, 2)->default(0)->after('pt_amount');
                $t->decimal('lwf_employer', 12, 2)->default(0)->after('lwf_employee');
                $t->decimal('vpf_amount', 12, 2)->default(0)->after('pf_employee');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('hr_employee_details')) {
            Schema::table('hr_employee_details', fn (Blueprint $t) => $t->dropColumn([
                'pf_applicable', 'eps_applicable', 'esic_applicable', 'pt_applicable',
                'lwf_applicable', 'gratuity_applicable', 'pf_joining_date',
                'vpf_amount', 'vpf_percent', 'restrict_pf_to_ceiling',
                'esic_dispensary', 'is_disabled',
            ]));
        }

        if (Schema::hasTable('hr_employees') && Schema::hasColumn('hr_employees', 'branch')) {
            Schema::table('hr_employees', fn (Blueprint $t) => $t->dropColumn('branch'));
        }

        if (Schema::hasTable('hr_payroll_records')) {
            Schema::table('hr_payroll_records', fn (Blueprint $t) => $t->dropColumn(['lwf_employee', 'lwf_employer', 'vpf_amount']));
        }
    }
};
