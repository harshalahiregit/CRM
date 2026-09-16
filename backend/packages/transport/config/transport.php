<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Role mapping
    |---------------------------------------------------------------------------
    |
    | Step 11's permission sheet is keyed on nine business roles. The CRM knows
    | accounts by `users.role` (account type) and `users.internal_role` (a
    | staff_roles slug). Nothing in the package says how one becomes the other,
    | so this map is the decision — and it is deliberately empty.
    |
    | Until it is filled in with sign-off, only `users.role = admin` resolves to
    | a transport role, and everyone else is refused everything. That is not a
    | bug to work around; it is the missing decision made visible. Filling this
    | in IS the decision, so it should be reviewed like one.
    |
    | Key   — a users.internal_role slug, or a users.role value as a fallback.
    | Value — one of Transport\Access\PermissionRegistry::ROLES:
    |         ceo_owner, operations, dispatcher, accounts, approver,
    |         driver, customer, supplier, admin
    |
    | Run `php artisan transport:roles` to list the role values actually held by
    | accounts in a tenant, so the map is written against reality rather than
    | against a guess about what slugs exist.
    |
    | A proposed starting point, NOT applied, pending sign-off:
    |
    |     'accounts'           => PermissionRegistry::ROLE_ACCOUNTS,
    |     'client'             => PermissionRegistry::ROLE_CUSTOMER,
    |     'purchase_vendor'    => PermissionRegistry::ROLE_SUPPLIER,
    |     'third_party_vendor' => PermissionRegistry::ROLE_SUPPLIER,
    |
    | Even these are judgement calls: `accounts` is a CRM staff role and
    | Accounts in the sheet approves advances and expenses, which is a financial
    | control, not a job title. Whoever signs this off is deciding who may
    | approve money.
    |
    */

    'roles' => [
        'map' => [
            // intentionally empty — see above
        ],
    ],

];
