<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrExternalCompany;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Support\Hr\CompanyAccountStatus as St;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * HR-side lifecycle for self-registered external companies: review the pending
 * queue, approve (activate company + its logins) or reject. This is the approval
 * step the codebase previously lacked for self-registered users.
 */
class CompanyAccountService
{
    public function __construct(private NotificationService $notifications)
    {
    }

    /** Companies awaiting HR approval, in the agency tenant. */
    public function pending(int $tenantId): Collection
    {
        return HrExternalCompany::where('tenant_id', $tenantId)
            ->where('status', St::PENDING_APPROVAL)
            ->with('users:id,external_company_id,name,email,internal_role,status')
            ->latest()
            ->get();
    }

    public function approve(HrExternalCompany $company, User $actor): HrExternalCompany
    {
        if ($company->status === St::ACTIVE) {
            throw new BusinessException('This company is already active.', 422);
        }

        DB::transaction(function () use ($company, $actor) {
            $company->update(['status' => St::ACTIVE]);
            // Activate the company's pending logins together with the company.
            User::where('external_company_id', $company->id)->where('status', 'pending')
                ->update(['status' => 'active']);
            $company->recordAudit('Company approved', $actor, $company->name, ['client_visible' => true]);
        });

        $this->notifications->email(
            $company->contact_email,
            'Your company account is approved',
            "Hello {$company->contact_person},\n\nYour company \"{$company->name}\" ({$company->company_code}) has been approved. You can now log in to your company portal and start creating hiring requests.\n\n— Recruitment Team",
            ['company_id' => $company->id, 'event' => 'company_approved'],
        );

        return $company->fresh('users');
    }

    public function reject(HrExternalCompany $company, User $actor, ?string $reason): HrExternalCompany
    {
        if ($company->status === St::ACTIVE) {
            throw new BusinessException('An active company cannot be rejected.', 422);
        }

        DB::transaction(function () use ($company, $actor, $reason) {
            $company->update(['status' => St::REJECTED, 'notes' => $reason]);
            User::where('external_company_id', $company->id)->where('status', 'pending')
                ->update(['status' => 'rejected']);
            $company->recordAudit('Company registration rejected', $actor, $reason);
        });

        $this->notifications->email(
            $company->contact_email,
            'Update on your company registration',
            "Hello {$company->contact_person},\n\nWe're unable to approve the registration for \"{$company->name}\" at this time.".($reason ? "\n\nReason: {$reason}" : '')."\n\nPlease contact our team for assistance.\n\n— Recruitment Team",
            ['company_id' => $company->id, 'event' => 'company_rejected'],
        );

        return $company->fresh('users');
    }
}
