<?php

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;

/**
 * MySQL refuses any identifier longer than 64 characters. SQLite does not care,
 * and the whole suite runs on SQLite — so an over-long index name passes every
 * test, every CI run, and then aborts the migration on the production MySQL box
 * halfway through, leaving tables created and the migration unrecorded.
 *
 * That is exactly what happened with
 * `client_vault_access_log_tenant_id_vault_entry_id_created_at_index` (65 chars).
 *
 * This reads the migration FILES rather than running them, because the failure
 * is in a name Laravel derives from table + columns, which is knowable without
 * a database of any kind — and without booting the framework, which is why it
 * extends PHPUnit's TestCase directly and resolves the path relatively.
 */
class MigrationIdentifierLengthTest extends TestCase
{
    private const MYSQL_MAX_IDENTIFIER = 64;

    /**
     * Identifiers that were already over the limit before this check existed.
     *
     * They survive on the live database only because their migrations are
     * recorded as already run, so MySQL is never asked to create the name. A
     * FRESH MySQL install would still fail on every one of them — they are
     * grandfathered here so this test can guard new work, not silently
     * blessed. Renaming them is a separate job for whoever owns each module.
     */
    private const GRANDFATHERED = [
        'acc_bank_statement_lines_tenant_id_bank_account_id_txn_date_index',
        'acc_reconciliation_lines_reconciliation_id_voucher_line_id_unique',
        'compliance_signatures_tenant_id_compliance_checklist_id_tier_index',
        'hr_employee_leave_balances_tenant_id_employee_id_leave_type_id_status_index',
        'hr_employee_onboarding_documents_tenant_id_onboarding_id_category_index',
        'hr_employee_onboarding_section_status_onboarding_id_section_unique',
        'hr_employee_onboarding_tasks_tenant_id_onboarding_id_category_index',
        'hr_interview_round_questions_interview_round_id_question_id_unique',
        'hr_probation_reviews_tenant_id_employee_probation_id_review_no_unique',
        'inventory_warehouse_env_readings_tenant_id_warehouse_id_recorded_at_index',
        'purchase_credit_applications_tenant_id_purchase_debit_note_id_index',
        'purchase_document_versions_purchase_document_id_version_no_unique',
        'purchase_kickoff_participants_tenant_id_purchase_kickoff_meeting_id_index',
    ];

    public function test_no_migration_generates_an_identifier_mysql_will_reject(): void
    {
        $tooLong = [];

        foreach (glob(__DIR__.'/../../../database/migrations/*.php') as $path) {
            $table = null;

            foreach (file($path) as $lineNo => $line) {
                // Track which table the following index() calls belong to.
                if (preg_match("/Schema::(?:create|table)\('([a-z0-9_]+)'/", $line, $m)) {
                    $table = $m[1];
                }

                if ($table === null) {
                    continue;
                }

                // Only unnamed index/unique calls derive a name — a second
                // argument means the author chose one, and it is used verbatim.
                foreach ([['index', '_index'], ['unique', '_unique']] as [$method, $suffix]) {
                    if (! preg_match('/\$\w+->'.$method.'\(\[([^\]]+)\]\s*\)/', $line, $m)) {
                        continue;
                    }

                    $columns = array_map(
                        fn ($c) => trim(trim($c), "'\" "),
                        explode(',', $m[1])
                    );

                    $name = $table.'_'.implode('_', $columns).$suffix;

                    if (in_array($name, self::GRANDFATHERED, true)) {
                        continue;
                    }

                    if (strlen($name) > self::MYSQL_MAX_IDENTIFIER) {
                        $tooLong[] = sprintf(
                            '%s:%d  %s (%d chars)',
                            basename($path),
                            $lineNo + 1,
                            $name,
                            strlen($name)
                        );
                    }
                }
            }
        }

        $this->assertSame([], $tooLong, sprintf(
            "These generated identifiers exceed MySQL's %d-character limit and will\n".
            "abort `migrate` on production while passing every SQLite test.\n".
            "Give each one an explicit short name as the second argument to index():\n\n  %s\n",
            self::MYSQL_MAX_IDENTIFIER,
            implode("\n  ", $tooLong)
        ));
    }
}
