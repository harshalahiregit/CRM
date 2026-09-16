<?php

namespace Transport\Access;

/**
 * Step 11's permission registry, copied rather than interpreted.
 *
 * PERM-001 to PERM-013 are the only transport permissions that exist. Step 11 is
 * the canonical technical authority for permissions, so this file is a
 * transcription of its Permissions sheet and nothing else — no row has been
 * added, merged or renamed to make the code tidier. When the sheet changes, this
 * changes with it through a registry change record, never the other way round.
 *
 * ── Why this is not the host's staff grid ──
 *
 * The CRM already has a permission grid (App\Support\Hr\StaffPermission) and we
 * use it: EnsureTransportPermission still refuses portal accounts through the
 * host's own rules. But that grid is module × {view_own, view_global, create,
 * edit, delete}, and transport permissions are business actions — approve,
 * assign, close, submit, record. STOS-SEC §36 is explicit that "CRUD is not
 * enough … invoice.approve rather than only invoice.edit". Five CRUD verbs
 * cannot express PERM-003, 004, 005, 007, 009, 011 or 013, so bending them to
 * fit would quietly drop seven of the thirteen controls.
 *
 * ── Why a grant is not a boolean ──
 *
 * The sheet answers with four values, not two. `Own` and `Assigned` are narrower
 * grants, not softer ones: a driver may see their own trips, a supplier may see
 * the trips assigned to them. Collapsing either to "true" hands every trip in
 * the company to a driver, and collapsing to "false" stops them working. The
 * gate therefore returns a scope and the caller narrows its query.
 */
final class PermissionRegistry
{
    /** Full access to the action across the tenant. */
    public const FULL = 'full';

    /** Refused. */
    public const NONE = 'none';

    /** Only records the actor owns — their own advances, their own trips. */
    public const OWN = 'own';

    /** Only records assigned to the actor — a supplier's allocated trips. */
    public const ASSIGNED = 'assigned';

    /**
     * Permitted, but the sheet marks it with an asterisk whose footnote is not
     * in the package. Treated as refused until somebody produces the footnote:
     * an asterisk on "may modify the canonical registry" is far more likely to
     * mean "under change control" than "freely", and guessing the generous
     * reading is how a governance control stops being one.
     */
    public const CONDITIONAL = 'conditional';

    /**
     * The nine roles the sheet columns are keyed on.
     *
     * These are business roles, not the CRM's account types. How a `users` row
     * becomes one of these is a mapping the package never states — see
     * RoleResolver, which refuses to invent it.
     */
    public const ROLE_OWNER      = 'ceo_owner';
    public const ROLE_OPERATIONS = 'operations';
    public const ROLE_DISPATCHER = 'dispatcher';
    public const ROLE_ACCOUNTS   = 'accounts';
    public const ROLE_APPROVER   = 'approver';
    public const ROLE_DRIVER     = 'driver';
    public const ROLE_CUSTOMER   = 'customer';
    public const ROLE_SUPPLIER   = 'supplier';
    public const ROLE_ADMIN      = 'admin';

    public const ROLES = [
        self::ROLE_OWNER,
        self::ROLE_OPERATIONS,
        self::ROLE_DISPATCHER,
        self::ROLE_ACCOUNTS,
        self::ROLE_APPROVER,
        self::ROLE_DRIVER,
        self::ROLE_CUSTOMER,
        self::ROLE_SUPPLIER,
        self::ROLE_ADMIN,
    ];

    /**
     * Step 11 Permissions sheet, transcribed.
     *
     * Keyed "domain.action" because that is how a route names what it needs.
     * Every row carries its PERM id so a denial can be traced back to the sheet
     * that caused it.
     */
    public const MATRIX = [
        'trip.view' => [
            'id'     => 'PERM-001',
            'grants' => [
                self::ROLE_OWNER => self::FULL,     self::ROLE_OPERATIONS => self::FULL,
                self::ROLE_DISPATCHER => self::FULL, self::ROLE_ACCOUNTS => self::FULL,
                self::ROLE_APPROVER => self::FULL,  self::ROLE_DRIVER => self::OWN,
                self::ROLE_CUSTOMER => self::OWN,   self::ROLE_SUPPLIER => self::ASSIGNED,
                self::ROLE_ADMIN => self::FULL,
            ],
        ],
        'trip.create' => [
            'id'     => 'PERM-002',
            'grants' => [
                self::ROLE_OWNER => self::FULL,     self::ROLE_OPERATIONS => self::FULL,
                self::ROLE_DISPATCHER => self::FULL, self::ROLE_ACCOUNTS => self::NONE,
                self::ROLE_APPROVER => self::NONE,  self::ROLE_DRIVER => self::NONE,
                self::ROLE_CUSTOMER => self::NONE,  self::ROLE_SUPPLIER => self::NONE,
                self::ROLE_ADMIN => self::FULL,
            ],
        ],
        'trip.approve' => [
            'id'     => 'PERM-003',
            'grants' => [
                self::ROLE_OWNER => self::FULL,      self::ROLE_OPERATIONS => self::FULL,
                self::ROLE_DISPATCHER => self::NONE, self::ROLE_ACCOUNTS => self::FULL,
                self::ROLE_APPROVER => self::FULL,   self::ROLE_DRIVER => self::NONE,
                self::ROLE_CUSTOMER => self::NONE,   self::ROLE_SUPPLIER => self::NONE,
                self::ROLE_ADMIN => self::FULL,
            ],
        ],
        'trip.assign' => [
            'id'     => 'PERM-004',
            'grants' => [
                self::ROLE_OWNER => self::FULL,      self::ROLE_OPERATIONS => self::FULL,
                self::ROLE_DISPATCHER => self::FULL, self::ROLE_ACCOUNTS => self::NONE,
                self::ROLE_APPROVER => self::NONE,   self::ROLE_DRIVER => self::NONE,
                self::ROLE_CUSTOMER => self::NONE,   self::ROLE_SUPPLIER => self::NONE,
                self::ROLE_ADMIN => self::FULL,
            ],
        ],
        'trip.close' => [
            'id'     => 'PERM-005',
            'grants' => [
                self::ROLE_OWNER => self::FULL,      self::ROLE_OPERATIONS => self::FULL,
                self::ROLE_DISPATCHER => self::NONE, self::ROLE_ACCOUNTS => self::FULL,
                self::ROLE_APPROVER => self::FULL,   self::ROLE_DRIVER => self::NONE,
                self::ROLE_CUSTOMER => self::NONE,   self::ROLE_SUPPLIER => self::NONE,
                self::ROLE_ADMIN => self::FULL,
            ],
        ],
        'advance.request' => [
            'id'     => 'PERM-006',
            'grants' => [
                self::ROLE_OWNER => self::FULL,      self::ROLE_OPERATIONS => self::FULL,
                self::ROLE_DISPATCHER => self::FULL, self::ROLE_ACCOUNTS => self::FULL,
                self::ROLE_APPROVER => self::NONE,   self::ROLE_DRIVER => self::OWN,
                self::ROLE_CUSTOMER => self::NONE,   self::ROLE_SUPPLIER => self::NONE,
                self::ROLE_ADMIN => self::FULL,
            ],
        ],
        'advance.approve' => [
            'id'     => 'PERM-007',
            'grants' => [
                self::ROLE_OWNER => self::FULL,      self::ROLE_OPERATIONS => self::NONE,
                self::ROLE_DISPATCHER => self::NONE, self::ROLE_ACCOUNTS => self::FULL,
                self::ROLE_APPROVER => self::FULL,   self::ROLE_DRIVER => self::NONE,
                self::ROLE_CUSTOMER => self::NONE,   self::ROLE_SUPPLIER => self::NONE,
                self::ROLE_ADMIN => self::FULL,
            ],
        ],
        'expense.submit' => [
            'id'     => 'PERM-008',
            'grants' => [
                self::ROLE_OWNER => self::FULL,      self::ROLE_OPERATIONS => self::FULL,
                self::ROLE_DISPATCHER => self::FULL, self::ROLE_ACCOUNTS => self::FULL,
                self::ROLE_APPROVER => self::NONE,   self::ROLE_DRIVER => self::OWN,
                self::ROLE_CUSTOMER => self::NONE,   self::ROLE_SUPPLIER => self::NONE,
                self::ROLE_ADMIN => self::FULL,
            ],
        ],
        'expense.approve' => [
            'id'     => 'PERM-009',
            'grants' => [
                self::ROLE_OWNER => self::FULL,      self::ROLE_OPERATIONS => self::NONE,
                self::ROLE_DISPATCHER => self::NONE, self::ROLE_ACCOUNTS => self::FULL,
                self::ROLE_APPROVER => self::FULL,   self::ROLE_DRIVER => self::NONE,
                self::ROLE_CUSTOMER => self::NONE,   self::ROLE_SUPPLIER => self::NONE,
                self::ROLE_ADMIN => self::FULL,
            ],
        ],
        'pod.submit' => [
            'id'     => 'PERM-010',
            'grants' => [
                self::ROLE_OWNER => self::FULL,      self::ROLE_OPERATIONS => self::FULL,
                self::ROLE_DISPATCHER => self::FULL, self::ROLE_ACCOUNTS => self::NONE,
                self::ROLE_APPROVER => self::NONE,   self::ROLE_DRIVER => self::OWN,
                self::ROLE_CUSTOMER => self::NONE,   self::ROLE_SUPPLIER => self::ASSIGNED,
                self::ROLE_ADMIN => self::FULL,
            ],
        ],
        'collection.record' => [
            'id'     => 'PERM-011',
            'grants' => [
                self::ROLE_OWNER => self::FULL,      self::ROLE_OPERATIONS => self::NONE,
                self::ROLE_DISPATCHER => self::NONE, self::ROLE_ACCOUNTS => self::FULL,
                self::ROLE_APPROVER => self::FULL,   self::ROLE_DRIVER => self::NONE,
                self::ROLE_CUSTOMER => self::NONE,   self::ROLE_SUPPLIER => self::NONE,
                self::ROLE_ADMIN => self::FULL,
            ],
        ],
        'controlroom.view' => [
            'id'     => 'PERM-012',
            'grants' => [
                self::ROLE_OWNER => self::FULL,      self::ROLE_OPERATIONS => self::FULL,
                self::ROLE_DISPATCHER => self::NONE, self::ROLE_ACCOUNTS => self::FULL,
                self::ROLE_APPROVER => self::FULL,   self::ROLE_DRIVER => self::NONE,
                self::ROLE_CUSTOMER => self::OWN,    self::ROLE_SUPPLIER => self::OWN,
                self::ROLE_ADMIN => self::FULL,
            ],
        ],
        'registry.modify' => [
            'id'     => 'PERM-013',
            'grants' => [
                self::ROLE_OWNER => self::FULL,      self::ROLE_OPERATIONS => self::NONE,
                self::ROLE_DISPATCHER => self::NONE, self::ROLE_ACCOUNTS => self::NONE,
                self::ROLE_APPROVER => self::NONE,   self::ROLE_DRIVER => self::NONE,
                self::ROLE_CUSTOMER => self::NONE,   self::ROLE_SUPPLIER => self::NONE,
                // The sheet says "Y*". The footnote is not in the package.
                self::ROLE_ADMIN => self::CONDITIONAL,
            ],
        ],
    ];

    /**
     * Actions the specifications require but the registry never granted.
     *
     * Deny-by-default means an unregistered action is not "unspecified", it is
     * REFUSED — the dispatch gate cannot be overridden, a compliance block
     * cannot be lifted, a document cannot be verified, an exception cannot be
     * waived, because nobody has a permission that allows it.
     *
     * These are listed rather than silently absent so that a developer who
     * reaches for one finds out why it fails, and so the list can be handed to
     * whoever owns the registry. Adding a key here grants nothing; it only
     * documents the hole. BLK-08 in docs/transport/TEAM-CONTRACTS.md.
     */
    public const UNREGISTERED = [
        'trip.dispatch'         => 'SNG-TRN-009 dispatches trips; no PERM row exists for it.',
        'pretrip.complete'      => 'SNG-TRN-010 makes pre-trip a blocking P0 gate; no PERM row exists.',
        'compliance.override'   => 'STOS-SEC §40 and §106 require a restricted override; no PERM row exists.',
        'exception.waive'       => 'BR-P0-011 and Step 3 TRP-P0-012 allow an owner waiver; no PERM row exists.',
        'document.verify'       => 'STOS-DOC §29 gates billing on verification; no PERM row exists.',
        'billing.prepare'       => 'API-010 names permission transport.billing.prepare; no PERM row exists.',
        'exception.create'      => 'API-007 names permission transport.exception.create; no PERM row exists.',
    ];

    /** Is this a permission the registry actually defines? */
    public static function defines(string $domain, string $action): bool
    {
        return isset(self::MATRIX[self::key($domain, $action)]);
    }

    /** The PERM id behind a permission, for error messages and audit metadata. */
    public static function idFor(string $domain, string $action): ?string
    {
        return self::MATRIX[self::key($domain, $action)]['id'] ?? null;
    }

    /** Why a known-missing action is refused, if it is one of the known holes. */
    public static function whyUnregistered(string $domain, string $action): ?string
    {
        return self::UNREGISTERED[self::key($domain, $action)] ?? null;
    }

    public static function key(string $domain, string $action): string
    {
        return strtolower($domain).'.'.strtolower($action);
    }
}
