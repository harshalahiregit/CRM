<?php

namespace Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * Patterns the front end has agreed not to add any more of.
 *
 * Three developers built three separate house styles. `alert()` appears 202
 * times in one owner's modules and nowhere else; `window.confirm` 70 times
 * where a styled `ConfirmDialog` already exists; the same purple gradient is
 * copy-pasted into 274 places rather than living in one button. A user moving
 * between modules gets a toast on one screen and a blocking browser dialog on
 * the next.
 *
 * Migrating all of that is a separate job that touches other people's modules.
 * This does the part that can be done today: it records exactly what exists and
 * fails if a file grows a NEW one. The counts are a ratchet — a file may drop
 * below its recorded number, and this test tells you to lower it. It may never
 * go up, and a file not listed may not introduce the pattern at all.
 *
 * Deliberately a count per file rather than a global total, so one module
 * cleaning up cannot mask another module regressing.
 *
 * components/ui/ is exempt: the shared button is exactly where the gradient
 * SHOULD live.
 */
class BannedPatternsTest extends TestCase
{
    private const SRC = __DIR__.'/../../../../frontend/src';

    /** Pattern => human explanation of what to use instead. */
    private const BANNED = [
        'alert('          => ['/(?<![\w.$])alert\s*\(/',           'use the shared useToast() — a browser alert blocks the page, cannot be styled, and looks like a virus warning on a phone'],
        'window.confirm'  => ['/window\.confirm\s*\(/',            'use ConfirmDialog (106 uses already) or ConfirmIconButton for an inline row delete'],
        'inline-gradient' => ['/linear-gradient\(135deg,\s*#7C3AED/', 'use the shared button component — this gradient is copy-pasted into 274 places and cannot be restyled centrally'],
    ];

    /**
     * What exists today, per file. A ratchet, not an allow-list: these numbers
     * may only ever go down.
     */
    private const GRANDFATHERED = [
        'alert(' => [
        'components/admin/StaffModal.jsx' => 1,
        'modules/hr/pages/JobPostings.jsx' => 6,
        'modules/hr/pages/JobWorkspace.jsx' => 4,
        'modules/hr/pages/ManpowerRequests.jsx' => 4,
        'modules/inventory/pages/InventorySettings.jsx' => 1,
        'modules/projects/pages/ProjectDetail.jsx' => 1,
        'modules/purchase/components/PurchaseVendorDocuments.jsx' => 4,
        'modules/purchase/pages/PurchaseAnalytics.jsx' => 1,
        'modules/purchase/pages/PurchaseCapaRegister.jsx' => 1,
        'modules/purchase/pages/PurchaseDebitNotes.jsx' => 10,
        'modules/purchase/pages/PurchaseInspections.jsx' => 1,
        'modules/purchase/pages/PurchaseInvoices.jsx' => 9,
        'modules/purchase/pages/PurchaseNcr.jsx' => 1,
        'modules/purchase/pages/PurchaseOffboarding.jsx' => 1,
        'modules/purchase/pages/PurchaseOrderReturns.jsx' => 1,
        'modules/purchase/pages/PurchaseOrders.jsx' => 10,
        'modules/purchase/pages/PurchaseRequests.jsx' => 5,
        'modules/purchase/pages/PurchaseVendorItems.jsx' => 1,
        'modules/purchase/pages/PurchaseViolations.jsx' => 1,
        'modules/purchase/pages/vendor-detail/PurchaseVendorDetailLayout.jsx' => 2,
        'modules/purchase/pages/vendor-detail/vendorDetailTabs.jsx' => 2,
        'modules/tasks/components/VendorEmployeeCascadePicker.jsx' => 2,
        'modules/tpv/components/TpvVendorContacts.jsx' => 7,
        'modules/tpv/components/TpvVendorDocuments.jsx' => 3,
        'modules/tpv/components/VendorAttachmentsPanel.jsx' => 5,
        'modules/tpv/components/VendorNotesPanel.jsx' => 1,
        'modules/tpv/components/VendorRemindersPanel.jsx' => 2,
        'modules/tpv/pages/TpvAnalytics.jsx' => 1,
        'modules/tpv/pages/TpvApprovals.jsx' => 2,
        'modules/tpv/pages/TpvCapaRegister.jsx' => 1,
        'modules/tpv/pages/TpvInspections.jsx' => 1,
        'modules/tpv/pages/TpvNcr.jsx' => 1,
        'modules/tpv/pages/TpvOffboarding.jsx' => 1,
        'modules/tpv/pages/TpvOnboardingWizard.jsx' => 18,
        'modules/tpv/pages/TpvOnboardings.jsx' => 3,
        'modules/tpv/pages/TpvStrikes.jsx' => 3,
        'modules/tpv/pages/TpvVendorDetail.jsx' => 7,
        'modules/tpv/pages/TpvVendors.jsx' => 2,
        'modules/tpv/pages/TpvViolations.jsx' => 1,
        'modules/tpv/pages/TpvWorkerWizard.jsx' => 27,
        'modules/tpv/pages/TpvWorkers.jsx' => 19,
        'modules/tpv/pages/WorkforceDashboard.jsx' => 10,
        'pages/careers/CareerJobDetails.jsx' => 1,
        'pages/careers/ClientTrackingPortal.jsx' => 3,
        'pages/careers/HiringRequestPortal.jsx' => 3,
        'pages/purchase-portal/PurchasePortalKickoff.jsx' => 1,
        'pages/settings/ActiveSessions.jsx' => 3,
        ],
        'window.confirm' => [
        'lib/confirmClose.jsx' => 1,
        'modules/hr/components/ExitQuestionnaires.jsx' => 1,
        'modules/hr/components/QuizBuilder.jsx' => 2,
        'modules/hr/components/QuizRunner.jsx' => 1,
        'modules/hr/components/ScheduleInterviewDrawer.jsx' => 1,
        'modules/hr/components/VariableEarnings.jsx' => 1,
        'modules/hr/components/operations/LoanManagement.jsx' => 1,
        'modules/hr/components/operations/ShiftManagement.jsx' => 2,
        'modules/hr/components/operations/WorkplaceManagement.jsx' => 1,
        'modules/hr/pages/CandidateProfile.jsx' => 3,
        'modules/hr/pages/CompanyApprovals.jsx' => 1,
        'modules/hr/pages/EmployeeProfile.jsx' => 1,
        'modules/hr/pages/EmployeeSurveys.jsx' => 2,
        'modules/hr/pages/InterviewQuestionBank.jsx' => 2,
        'modules/hr/pages/Interviews.jsx' => 1,
        'modules/hr/pages/JobListView.jsx' => 1,
        'modules/hr/pages/RecruitmentServices.jsx' => 4,
        'modules/hr/pages/StatutorySettings.jsx' => 1,
        'modules/hr/pages/TaxDeclarations.jsx' => 1,
        'modules/purchase/components/PurchaseVendorContacts.jsx' => 1,
        'modules/purchase/pages/PurchaseCapaRegister.jsx' => 1,
        'modules/purchase/pages/PurchaseInspections.jsx' => 1,
        'modules/purchase/pages/PurchaseNcr.jsx' => 1,
        'modules/purchase/pages/PurchaseOffboarding.jsx' => 2,
        'modules/purchase/pages/PurchaseOrderReturns.jsx' => 1,
        'modules/purchase/pages/PurchaseRenewals.jsx' => 1,
        'modules/purchase/pages/PurchaseSettings.jsx' => 1,
        'modules/purchase/pages/PurchaseVendorItems.jsx' => 1,
        'modules/purchase/pages/PurchaseViolations.jsx' => 2,
        'modules/purchase/pages/vendor-detail/vendorDetailTabs.jsx' => 1,
        'modules/settings/pages/DocumentNumberingSettings.jsx' => 2,
        'modules/settings/pages/EmailTemplatesSettings.jsx' => 2,
        'modules/shared/pages/KickoffMeetings.jsx' => 1,
        'modules/tpv/components/VendorAttachmentsPanel.jsx' => 2,
        'modules/tpv/components/VendorNotesPanel.jsx' => 1,
        'modules/tpv/components/VendorRemindersPanel.jsx' => 2,
        'modules/tpv/pages/TpvCapaRegister.jsx' => 1,
        'modules/tpv/pages/TpvContracts.jsx' => 1,
        'modules/tpv/pages/TpvInspections.jsx' => 1,
        'modules/tpv/pages/TpvNcr.jsx' => 1,
        'modules/tpv/pages/TpvOffboarding.jsx' => 2,
        'modules/tpv/pages/TpvRenewals.jsx' => 1,
        'modules/tpv/pages/TpvViolations.jsx' => 2,
        'modules/tpv/pages/TpvWorkPackages.jsx' => 1,
        'modules/tpv/pages/TpvWorkerWizard.jsx' => 3,
        'modules/tpv/pages/TpvWorkers.jsx' => 1,
        'pages/company-portal/CompanyHiringRequests.jsx' => 1,
        'pages/company-portal/CompanyInterviews.jsx' => 1,
        'pages/company-portal/CompanyRequestDetail.jsx' => 1,
        'pages/company-portal/CompanySettings.jsx' => 1,
        ],
        'inline-gradient' => [
        'components/admin/StaffModal.jsx' => 1,
        'components/layout/ModuleShell.jsx' => 1,
        'modules/accounts/pages/Bills.jsx' => 1,
        'modules/accounts/pages/RegisterDetail.jsx' => 1,
        'modules/accounts/pages/Settings.jsx' => 1,
        'modules/accounts/pages/reports/Ageing.jsx' => 1,
        'modules/customer/components/ActivitiesTab.jsx' => 2,
        'modules/customer/components/AdminOrderPicker.jsx' => 1,
        'modules/customer/components/CustomFieldForm.jsx' => 1,
        'modules/customer/components/CustomFieldsManager.jsx' => 1,
        'modules/customer/components/CustomerExperienceTab.jsx' => 2,
        'modules/customer/components/MapTab.jsx' => 1,
        'modules/customer/components/NotesTab.jsx' => 1,
        'modules/customer/components/RecordTab.jsx' => 2,
        'modules/customer/components/StepperNav.jsx' => 1,
        'modules/customer/components/SupportTab.jsx' => 1,
        'modules/customer/pages/CustomerDetail.jsx' => 7,
        'modules/customer/pages/Customers.jsx' => 2,
        'modules/customer/pages/GroupReports.jsx' => 1,
        'modules/hr/HRLayout.jsx' => 1,
        'modules/hr/components/CandidateQuickActions.jsx' => 1,
        'modules/hr/components/DateTimePicker.jsx' => 2,
        'modules/hr/components/EmployeeSkillsPanel.jsx' => 1,
        'modules/hr/components/ExitQuestionnaires.jsx' => 2,
        'modules/hr/components/GenerateOfferDrawer.jsx' => 2,
        'modules/hr/components/InterviewFeedbackDrawer.jsx' => 2,
        'modules/hr/components/InterviewQuestionPanel.jsx' => 3,
        'modules/hr/components/QuizBuilder.jsx' => 1,
        'modules/hr/components/QuizRunner.jsx' => 1,
        'modules/hr/components/ScheduleInterviewDrawer.jsx' => 4,
        'modules/hr/components/SkipRoundDialog.jsx' => 1,
        'modules/hr/components/VariableEarnings.jsx' => 2,
        'modules/hr/components/operations/EmployeeMovements.jsx' => 1,
        'modules/hr/components/operations/LoanManagement.jsx' => 1,
        'modules/hr/components/operations/ShiftManagement.jsx' => 1,
        'modules/hr/components/operations/WorkplaceManagement.jsx' => 1,
        'modules/hr/pages/Attendance.jsx' => 2,
        'modules/hr/pages/CandidateProfile.jsx' => 9,
        'modules/hr/pages/Candidates.jsx' => 4,
        'modules/hr/pages/EmployeeOnboarding.jsx' => 1,
        'modules/hr/pages/EmployeeOnboardingDetail.jsx' => 3,
        'modules/hr/pages/EmployeeProfile.jsx' => 3,
        'modules/hr/pages/EmployeeSurveys.jsx' => 1,
        'modules/hr/pages/Employees.jsx' => 4,
        'modules/hr/pages/ExitInterview.jsx' => 4,
        'modules/hr/pages/ExitManagement.jsx' => 1,
        'modules/hr/pages/ExitReports.jsx' => 1,
        'modules/hr/pages/HRDashboard.jsx' => 1,
        'modules/hr/pages/HrOperations.jsx' => 1,
        'modules/hr/pages/InterviewDetail.jsx' => 3,
        'modules/hr/pages/InterviewQuestionBank.jsx' => 7,
        'modules/hr/pages/Interviews.jsx' => 6,
        'modules/hr/pages/JobPostings.jsx' => 1,
        'modules/hr/pages/JobWorkspace.jsx' => 3,
        'modules/hr/pages/LearningDevelopment.jsx' => 1,
        'modules/hr/pages/LearningReports.jsx' => 1,
        'modules/hr/pages/LeaveManagement.jsx' => 1,
        'modules/hr/pages/LeaveReports.jsx' => 1,
        'modules/hr/pages/ManpowerRequests.jsx' => 6,
        'modules/hr/pages/OfferLetters.jsx' => 6,
        'modules/hr/pages/Onboarding.jsx' => 8,
        'modules/hr/pages/OrganizationSetup.jsx' => 1,
        'modules/hr/pages/Payroll.jsx' => 1,
        'modules/hr/pages/PayrollReports.jsx' => 1,
        'modules/hr/pages/Performance.jsx' => 1,
        'modules/hr/pages/ProbationManagement.jsx' => 1,
        'modules/hr/pages/ProbationReports.jsx' => 1,
        'modules/hr/pages/RecruitmentServices.jsx' => 6,
        'modules/hr/pages/SalaryReports.jsx' => 1,
        'modules/hr/pages/StatutorySettings.jsx' => 1,
        'modules/hr/pages/TaxDeclarations.jsx' => 1,
        'modules/purchase/pages/PurchaseDebitNotes.jsx' => 6,
        'modules/purchase/pages/PurchaseInvoices.jsx' => 5,
        'modules/purchase/pages/PurchaseOrders.jsx' => 5,
        'modules/purchase/pages/PurchaseRequests.jsx' => 5,
        'modules/sales/components/ContractDrawer.jsx' => 2,
        'modules/sales/components/FollowUpsPanel.jsx' => 1,
        'modules/sales/components/lead/LeadAppointmentsTab.jsx' => 1,
        'modules/sales/components/lead/LeadCustomFieldsTab.jsx' => 1,
        'modules/sales/components/lead/LeadEmailsTab.jsx' => 1,
        'modules/sales/components/lead/LeadTasksTab.jsx' => 1,
        'modules/sales/pages/Commission.jsx' => 1,
        'modules/sales/pages/ContractDetail.jsx' => 4,
        'modules/sales/pages/ContractPortal.jsx' => 1,
        'modules/sales/pages/CreditNotes.jsx' => 1,
        'modules/sales/pages/DeliveryNotes.jsx' => 1,
        'modules/sales/pages/Estimates.jsx' => 2,
        'modules/sales/pages/Invoices.jsx' => 2,
        'modules/sales/pages/Items.jsx' => 2,
        'modules/sales/pages/LeadDetail.jsx' => 1,
        'modules/sales/pages/PaymentLinks.jsx' => 1,
        'modules/sales/pages/Payments.jsx' => 1,
        'modules/sales/pages/ProposalDetail.jsx' => 3,
        'modules/sales/pages/Proposals.jsx' => 2,
        'modules/sales/pages/RetainerInvoices.jsx' => 1,
        'modules/sales/pages/SalesDashboard.jsx' => 1,
        'modules/sales/pages/SalesTasks.jsx' => 1,
        'modules/sales/pages/WebToLeadForms.jsx' => 1,
        'modules/sales/public/PublicLeadForm.jsx' => 1,
        'modules/settings/SettingsLayout.jsx' => 1,
        'modules/settings/pages/CustomFieldsSettings.jsx' => 2,
        'modules/settings/pages/EmailTemplatesSettings.jsx' => 1,
        'modules/settings/pages/ExpenseCategoriesSettings.jsx' => 1,
        'modules/settings/pages/TaxRatesSettings.jsx' => 1,
        'modules/shared/pages/KickoffMeetingDetail.jsx' => 2,
        'modules/tpv/components/TpvVendorContacts.jsx' => 1,
        'modules/tpv/components/VendorAttachmentsPanel.jsx' => 1,
        'modules/tpv/components/VendorCommercialPanel.jsx' => 1,
        'modules/tpv/components/VendorCustomersPanel.jsx' => 1,
        'modules/tpv/components/VendorSectionTable.jsx' => 1,
        'modules/tpv/pages/TpvEvidenceLocker.jsx' => 1,
        'modules/tpv/pages/TpvIncidents.jsx' => 1,
        'modules/tpv/pages/TpvOnboardingWizard.jsx' => 9,
        'modules/tpv/pages/TpvOnboardings.jsx' => 2,
        'modules/tpv/pages/TpvPermits.jsx' => 1,
        'modules/tpv/pages/TpvReports.jsx' => 1,
        'modules/tpv/pages/TpvSafetyEngagement.jsx' => 1,
        'modules/tpv/pages/TpvSiteRegisters.jsx' => 1,
        'modules/tpv/pages/TpvStrikes.jsx' => 1,
        'modules/tpv/pages/TpvWorkerWizard.jsx' => 1,
        'modules/tpv/pages/TpvWorkers.jsx' => 2,
        'modules/tpv/pages/WorkforceDashboard.jsx' => 1,
        'pages/admin/StaffManagementPage.jsx' => 2,
        'pages/auth/CompanyRegisterPage.jsx' => 3,
        'pages/auth/ForgotPasswordPage.jsx' => 1,
        'pages/auth/LoginPage.jsx' => 2,
        'pages/auth/SetPasswordPage.jsx' => 1,
        'pages/careers/ClientTrackingPortal.jsx' => 3,
        'pages/careers/HiringRequestPortal.jsx' => 2,
        'pages/client-portal/ClientPortalFeedback.jsx' => 1,
        // Follows the portal's own button convention (the same gradient is in
        // Feedback, Login and Shell). The portal needs the shared Btn extraction
        // too — recorded so it cannot grow, not blessed.
        'pages/client-portal/ClientPortalRecords.jsx' => 2,
        'pages/client-portal/ClientPortalLogin.jsx' => 1,
        'pages/client-portal/ClientPortalShell.jsx' => 1,
        'pages/company-portal/CompanyCandidates.jsx' => 4,
        'pages/company-portal/CompanyDashboard.jsx' => 1,
        'pages/company-portal/CompanyHiringRequests.jsx' => 2,
        'pages/company-portal/CompanyInterviews.jsx' => 1,
        'pages/company-portal/CompanyProfile.jsx' => 2,
        'pages/company-portal/CompanyRequestDetail.jsx' => 3,
        'pages/company-portal/CompanySettings.jsx' => 1,
        'pages/modules/ModulesPage.jsx' => 1,
        'pages/settings/MyProfile.jsx' => 1,
        'pages/vendor-portal/PortalDashboard.jsx' => 1,
        ],
    ];

    /** @return array<string,int> file => occurrences */
    private function countAll(string $regex): array
    {
        $found = [];
        $dir   = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SRC));

        foreach ($dir as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'jsx') {
                continue;
            }

            $rel = str_replace(realpath(self::SRC).'/', '', $file->getRealPath());

            // The shared components are where these patterns belong.
            if (str_starts_with($rel, 'components/ui/')) {
                continue;
            }

            $n = preg_match_all($regex, file_get_contents($file->getRealPath()));
            if ($n) {
                $found[$rel] = $n;
            }
        }

        return $found;
    }

    /** @dataProvider bannedPatterns */
    public function test_a_banned_pattern_is_not_added_anywhere_new(string $key): void
    {
        [$regex, $instead] = self::BANNED[$key];
        $actual = $this->countAll($regex);
        $allowed = self::GRANDFATHERED[$key];

        $problems = [];

        foreach ($actual as $file => $n) {
            $budget = $allowed[$file] ?? 0;

            if ($n > $budget) {
                $problems[] = $budget === 0
                    ? sprintf('%s introduces %s (%d) — %s', $file, $key, $n, $instead)
                    : sprintf('%s went from %d to %d uses of %s — %s', $file, $budget, $n, $key, $instead);
            }
        }

        $this->assertSame([], $problems, "\n  ".implode("\n  ", $problems)."\n");
    }

    /**
     * The ratchet only works if it tightens. When a file is cleaned up, its
     * recorded number must come down with it, or the budget silently allows a
     * future regression back to the old level.
     *
     * @dataProvider bannedPatterns
     */
    public function test_the_recorded_counts_are_not_stale(string $key): void
    {
        [$regex] = self::BANNED[$key];
        $actual  = $this->countAll($regex);
        $stale   = [];

        foreach (self::GRANDFATHERED[$key] as $file => $budget) {
            $now = $actual[$file] ?? 0;

            if ($now < $budget) {
                $stale[] = $now === 0
                    ? sprintf('%s no longer uses %s — remove its line from GRANDFATHERED', $file, $key)
                    : sprintf('%s is down to %d uses of %s — lower its recorded number from %d', $file, $now, $key, $budget);
            }
        }

        $this->assertSame([], $stale,
            "\n  Good news, then bookkeeping:\n  ".implode("\n  ", $stale)."\n");
    }

    public static function bannedPatterns(): array
    {
        return [
            'alert()'         => ['alert('],
            'window.confirm'  => ['window.confirm'],
            'inline gradient' => ['inline-gradient'],
        ];
    }
}
