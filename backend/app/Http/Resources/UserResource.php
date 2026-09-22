<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'email'         => $this->email,
            'role'          => $this->role,
            'internal_role' => $this->internal_role,
            'department'    => $this->department,
            'status'        => $this->status,
            'vendor_type'   => $this->vendor_type,
            'tpv_type'      => $this->tpv_type,
            'phone'         => $this->phone,
            'company'       => $this->company,
            'designation'   => $this->designation,
            'mail_from_name'  => $this->mail_from_name,
            'mail_from_email' => $this->mail_from_email,
            'avatar'        => $this->avatar,
            'tenant_id'     => $this->tenant_id,
            'external_company_id' => $this->external_company_id,
            'created_at'    => $this->created_at,

            // What this person may actually do, resolved server-side.
            //
            // The screens had no way to ask, so they guessed from `role` — the
            // sidebar rendered every HR management item for everybody and each
            // one 403'd on click, and no guess could ever be right anyway: the
            // advances gate asks whether anyone REPORTS to you, which is a
            // database question no role string answers.
            //
            // Shape: { module: 'global' | 'own' } for the modules this person can
            // see at all. Absent means no access. `can` lists every granted
            // capability for the finer questions (may they create? delete?).
            'permissions'   => $this->when(
                in_array($this->role, ['admin', 'staff'], true),
                fn () => $this->resolvePermissions(),
            ),
        ];
    }

    /**
     * @return array{scope: array<string,string>, can: array<string,array<string>>, is_admin: bool, capabilities: array<string,bool>}
     */
    private function resolvePermissions(): array
    {
        $service = app(\App\Services\Auth\StaffPermissionService::class);
        $user    = $this->resource;

        $scope = [];
        $can   = [];

        foreach (\App\Support\Hr\StaffPermission::MODULES as $module) {
            $width = $service->scope($user, $module);

            if ($width !== null) {
                $scope[$module] = $width;
            }

            $granted = array_values(array_filter(
                \App\Support\Hr\StaffPermission::CAPABILITIES,
                fn ($c) => $service->can($user, $c, $module),
            ));

            if ($granted !== []) {
                $can[$module] = $granted;
            }
        }

        return [
            'scope'    => $scope,
            'can'      => $can,
            // Stated rather than inferred from the lists: an admin bypasses the
            // grid, so "has every module" and "is an admin" are different facts.
            'is_admin' => $service->bypasses($user),

            /*
             | Server-computed answers, for the three rules the frontend had been
             | REBUILDING from role strings in modules/hr/constants.js:
             |
             |   canManageHrQueue = role==='admin' || internal_role==='hr_executive'
             |                      || ['hr_recruiter','hr_executive'].includes(...)
             |
             | Ten call sites across nine screens asked that. It was a fair copy
             | of the backend when it was written and is no longer one: it has no
             | hr_employees:view_global clause, so a CUSTOM ROLE configured in HR
             | Settings passes the server and is hidden by the screen — the exact
             | failure the configurable-role work exists to prevent. The approval
             | pair likewise predates the account-type guard.
             |
             | None of these is a new permission. Each is an existing backend
             | capability the frontend had no way to ask about, so it guessed.
             | Sending the ANSWER is what stops the two drifting again.
             */
            'capabilities' => [
                'hr_manage'  => $user->canManageHrQueue(),
                'approve_l1' => $user->canApproveL1(),
                'approve_l2' => $user->canApproveL2(),
            ],
        ];
    }
}
