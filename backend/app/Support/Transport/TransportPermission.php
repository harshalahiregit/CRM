<?php

namespace App\Support\Transport;

/**
 * The Transport permission vocabulary and matrix (SNG-TRN-028).
 *
 * Two things live here, and they come from two different places:
 *
 *  1. PERMISSION KEYS — specified. Step 11's API registry names them directly:
 *     transport.order.create (API-001), transport.trip.create (API-002),
 *     transport.trip.view (API-013), transport.trip.assign (API-004).
 *
 *  2. THE MATRIX — specified. Step 11's Permissions sheet, PERM-001..PERM-013,
 *     gives Domain x Action x Role. Only the rows this ticket needs are encoded;
 *     the rest arrive with the tickets that own them, so nothing here is a guess
 *     about advances, expenses, POD or collections.
 *
 * Step 11's rule is "deny by default": a key that is not listed is denied, and
 * an unknown key is a bug in the caller rather than a reason to allow.
 *
 * ── ONE THING IS NOT SPECIFIED ────────────────────────────────────────────
 * Step 11's matrix uses STOS's own role names — CEO/Owner, Operations,
 * Dispatcher, Accounts, Approver, Driver, Customer, Supplier, Admin. Sangoe's
 * users carry role = admin|staff|client|vendor|third_party_vendor|company plus
 * an internal_role. NO DOCUMENT MAPS ONE VOCABULARY ONTO THE OTHER.
 *
 * ROLE_MAP below is therefore the single inferred element in this file, kept in
 * one place precisely so it is visible and cheap to correct. It is deliberately
 * conservative: it grants only what the Sangoe role plainly implies and leaves
 * every ambiguous case unmapped (and therefore denied) rather than guessing
 * upward. It is flagged for confirmation and must not be widened silently.
 */
final class TransportPermission
{
    /* ── STOS roles, exactly as Step 11 names its matrix columns ───────── */
    public const ROLE_OWNER      = 'owner';
    public const ROLE_OPERATIONS = 'operations';
    public const ROLE_DISPATCHER = 'dispatcher';
    public const ROLE_ACCOUNTS   = 'accounts';
    public const ROLE_APPROVER   = 'approver';
    public const ROLE_DRIVER     = 'driver';
    public const ROLE_CUSTOMER   = 'customer';
    public const ROLE_SUPPLIER   = 'supplier';
    public const ROLE_ADMIN      = 'admin';

    public const ROLES = [
        self::ROLE_OWNER, self::ROLE_OPERATIONS, self::ROLE_DISPATCHER,
        self::ROLE_ACCOUNTS, self::ROLE_APPROVER, self::ROLE_DRIVER,
        self::ROLE_CUSTOMER, self::ROLE_SUPPLIER, self::ROLE_ADMIN,
    ];

    /* ── Scope qualifiers used by Step 11's matrix ──────────────────────── */
    /** Full access to every record in the tenant. */
    public const SCOPE_ALL = 'all';
    /** Step 11 writes "Own" — only records belonging to this actor. */
    public const SCOPE_OWN = 'own';
    /** Step 11 writes "Assigned" — only records this actor is assigned to. */
    public const SCOPE_ASSIGNED = 'assigned';

    /* ── Permission keys, from the Step 11 API registry ─────────────────── */
    public const ORDER_VIEW   = 'transport.order.view';
    public const ORDER_CREATE = 'transport.order.create';
    public const ORDER_UPDATE = 'transport.order.update';
    public const TRIP_VIEW    = 'transport.trip.view';
    public const TRIP_CREATE  = 'transport.trip.create';
    public const TRIP_ASSIGN  = 'transport.trip.assign';

    /* ── Master data (SNG-TRN-003 / 004). NOT IN THE REGISTRY — see D-8. ── */
    public const VEHICLE_VIEW   = 'transport.vehicle.view';
    public const VEHICLE_CREATE = 'transport.vehicle.create';
    public const VEHICLE_UPDATE = 'transport.vehicle.update';
    public const VEHICLE_DELETE = 'transport.vehicle.delete';
    public const DRIVER_VIEW    = 'transport.driver.view';
    public const DRIVER_CREATE  = 'transport.driver.create';
    public const DRIVER_UPDATE  = 'transport.driver.update';
    public const DRIVER_DELETE  = 'transport.driver.delete';

    /* ── Pre-trip checks (SNG-TRN-010). NOT IN THE REGISTRY — see D-21. ── */
    public const PRETRIP_VIEW    = 'transport.pretrip.view';
    public const PRETRIP_PERFORM = 'transport.pretrip.perform';

    /* ── Dispatch. NOT IN THE REGISTRY — see D-18/D-21. ─────────────────── */
    public const TRIP_DISPATCH = 'transport.trip.dispatch';

    /**
     * The matrix. Role => scope, for each permission.
     *
     * Trip rows are PERM-001 and PERM-002 verbatim.
     *
     * ORDER rows carry a caveat: Step 11's Permissions sheet has NO Order row at
     * all — it covers Trip, Advance, Expense, POD, Collection, ControlRoom and
     * Registry only, despite API-001 naming transport.order.create. Rather than
     * invent an Order matrix, the Order rows below mirror the Trip rows, which
     * is the narrowest defensible reading (an order is the trip's parent, and
     * nobody who may not create a trip has any reason to create its order).
     * FLAGGED: confirm or replace.
     */
    public const MATRIX = [
        // PERM-002 — Trip create: Owner Y, Operations Y, Dispatcher Y, Admin Y.
        self::TRIP_CREATE => [
            self::ROLE_OWNER      => self::SCOPE_ALL,
            self::ROLE_OPERATIONS => self::SCOPE_ALL,
            self::ROLE_DISPATCHER => self::SCOPE_ALL,
            self::ROLE_ADMIN      => self::SCOPE_ALL,
        ],
        // PERM-004 — Trip assign: Owner Y, Operations Y, Dispatcher Y, Admin Y.
        // Accounts N, Approver N, Driver N, Customer N, Supplier N.
        //
        // Identical to PERM-002's row, and that is the registry's choice rather
        // than a copy: whoever may create a trip may crew it. Written out in full
        // anyway instead of aliased to TRIP_CREATE, because the two are separate
        // registry rows that a later revision may separate in fact — PERM-003 and
        // PERM-005 already differ from both by excluding Dispatcher.
        //
        // Note what this row denies. Accounts and Approver can view, approve and
        // close a trip but cannot crew one; Supplier holds "Assigned" scope on
        // PERM-001 yet gets a flat N here, so a supplier can watch a trip they are
        // attached to and never choose its vehicle or driver. That asymmetry is
        // the point of the row.
        self::TRIP_ASSIGN => [
            self::ROLE_OWNER      => self::SCOPE_ALL,
            self::ROLE_OPERATIONS => self::SCOPE_ALL,
            self::ROLE_DISPATCHER => self::SCOPE_ALL,
            self::ROLE_ADMIN      => self::SCOPE_ALL,
        ],
        // PERM-001 — Trip view: Owner/Ops/Dispatcher/Accounts/Approver/Admin all;
        // Driver "Own", Customer "Own", Supplier "Assigned".
        self::TRIP_VIEW => [
            self::ROLE_OWNER      => self::SCOPE_ALL,
            self::ROLE_OPERATIONS => self::SCOPE_ALL,
            self::ROLE_DISPATCHER => self::SCOPE_ALL,
            self::ROLE_ACCOUNTS   => self::SCOPE_ALL,
            self::ROLE_APPROVER   => self::SCOPE_ALL,
            self::ROLE_ADMIN      => self::SCOPE_ALL,
            self::ROLE_DRIVER     => self::SCOPE_OWN,
            self::ROLE_CUSTOMER   => self::SCOPE_OWN,
            self::ROLE_SUPPLIER   => self::SCOPE_ASSIGNED,
        ],
        // Mirrors PERM-002 — see the caveat above.
        self::ORDER_CREATE => [
            self::ROLE_OWNER      => self::SCOPE_ALL,
            self::ROLE_OPERATIONS => self::SCOPE_ALL,
            self::ROLE_DISPATCHER => self::SCOPE_ALL,
            self::ROLE_ADMIN      => self::SCOPE_ALL,
        ],
        self::ORDER_UPDATE => [
            self::ROLE_OWNER      => self::SCOPE_ALL,
            self::ROLE_OPERATIONS => self::SCOPE_ALL,
            self::ROLE_DISPATCHER => self::SCOPE_ALL,
            self::ROLE_ADMIN      => self::SCOPE_ALL,
        ],
        /* ── Master data rows — REGISTRY DEFECT D-8 ─────────────────────
         *
         * Step 11's Permissions sheet has THIRTEEN rows and covers only Trip,
         * Advance, Expense, POD, Collection, ControlRoom and Registry. There is
         * no Vehicle or Driver domain anywhere in it, and the API registry
         * (API-001…015) has no master-data endpoint to borrow a key from. So
         * unlike PERM-004, none of the rows below is quoted from the registry.
         *
         * They are the narrowest reading that lets tickets 003 and 004 ship
         * their FE half at all, derived from the two things the package does say:
         *
         *   Both tickets' user story is "As an OPERATOR, I can maintain…", which
         *   maps to Operations; and PERM-013 (Registry modify) restricts master
         *   configuration to Owner and Admin. Master data sits between the two,
         *   so writes go to Owner/Operations/Admin and deletion — the only
         *   irreversible act — narrows to Owner/Admin.
         *
         *   Reads are wider than writes but narrower than PERM-001: Accounts and
         *   Approver need to see a vehicle to reason about cost and approval.
         *   Driver, Customer and Supplier get NOTHING, because PERM-001 grants
         *   them only "Own"/"Assigned" scope on a trip and there is no such thing
         *   as "your own" fleet master. Deny by default wins where the registry
         *   is silent.
         *
         * FLAGGED. Must be confirmed or replaced by the System Architect; see
         * docs/transport/registry-defects.md, D-8.
         */
        self::VEHICLE_VIEW => [
            self::ROLE_OWNER      => self::SCOPE_ALL,
            self::ROLE_OPERATIONS => self::SCOPE_ALL,
            self::ROLE_DISPATCHER => self::SCOPE_ALL,
            self::ROLE_ACCOUNTS   => self::SCOPE_ALL,
            self::ROLE_APPROVER   => self::SCOPE_ALL,
            self::ROLE_ADMIN      => self::SCOPE_ALL,
        ],
        self::VEHICLE_CREATE => [
            self::ROLE_OWNER      => self::SCOPE_ALL,
            self::ROLE_OPERATIONS => self::SCOPE_ALL,
            self::ROLE_ADMIN      => self::SCOPE_ALL,
        ],
        self::VEHICLE_UPDATE => [
            self::ROLE_OWNER      => self::SCOPE_ALL,
            self::ROLE_OPERATIONS => self::SCOPE_ALL,
            self::ROLE_ADMIN      => self::SCOPE_ALL,
        ],
        // Irreversible, so narrowest. FLEET §7 gives RETIRED/SOLD as the normal
        // end of a vehicle's life; deletion is for a record created in error.
        self::VEHICLE_DELETE => [
            self::ROLE_OWNER => self::SCOPE_ALL,
            self::ROLE_ADMIN => self::SCOPE_ALL,
        ],

        self::DRIVER_VIEW => [
            self::ROLE_OWNER      => self::SCOPE_ALL,
            self::ROLE_OPERATIONS => self::SCOPE_ALL,
            self::ROLE_DISPATCHER => self::SCOPE_ALL,
            self::ROLE_ACCOUNTS   => self::SCOPE_ALL,
            self::ROLE_APPROVER   => self::SCOPE_ALL,
            self::ROLE_ADMIN      => self::SCOPE_ALL,
        ],
        self::DRIVER_CREATE => [
            self::ROLE_OWNER      => self::SCOPE_ALL,
            self::ROLE_OPERATIONS => self::SCOPE_ALL,
            self::ROLE_ADMIN      => self::SCOPE_ALL,
        ],
        self::DRIVER_UPDATE => [
            self::ROLE_OWNER      => self::SCOPE_ALL,
            self::ROLE_OPERATIONS => self::SCOPE_ALL,
            self::ROLE_ADMIN      => self::SCOPE_ALL,
        ],
        self::DRIVER_DELETE => [
            self::ROLE_OWNER => self::SCOPE_ALL,
            self::ROLE_ADMIN => self::SCOPE_ALL,
        ],

        /* ── Pre-trip rows — REGISTRY DEFECT D-21 ──────────────────────
         *
         * Step 11's Permissions sheet has no Pretrip or Dispatch domain, so as
         * with D-8 nothing below is quoted. Worse than D-8, though: three
         * documents name three DIFFERENT actors for the same act, and one of
         * them is not a role this package defines.
         *
         *   FRS TRP-P0-005   "Supervisor/Driver", plus "Supervisor sign-off
         *                     for critical failures"
         *   UAT-004          "Supervisor"
         *   OPS §35          "Driver must complete required inspection"
         *   Step 11 SM-TRP   `allocated` and `dispatched` are owned by the
         *                     DISPATCHER
         *
         * SUPERVISOR IS NOT ONE OF STEP 11's NINE ROLES. It appears in no role
         * list anywhere in the 42 documents. Read against the nine that do
         * exist, a transport supervisor is Operations — the role that owns
         * operational execution — and the state owner is Dispatcher. Both get
         * the rows below.
         *
         * DRIVER IS NAMED BY TWO DOCUMENTS AND IS NOT IMPLEMENTABLE. This is
         * recorded rather than quietly dropped: `transport_drivers` links only
         * to an optional `hr_employee_id` and has NO user account, so a driver
         * cannot authenticate at all. OPS §33's driver app does not exist, and
         * SNG-TRN-026 (Offline Field Mode, P1, Backlog) is the ticket that would
         * create one. Until then a checklist is completed by staff on the
         * driver's behalf, and granting ROLE_DRIVER here would be a row that can
         * never be exercised — see UNIMPLEMENTABLE_ACTORS below.
         *
         * The shape follows PERM-004 (Trip assign) exactly, and deliberately:
         * whoever may crew a trip is who may certify that crew fit to leave.
         * Accounts and Approver can view but not perform, which mirrors their N
         * on PERM-004; Customer and Supplier get nothing at all, because a
         * dispatch block names a specific driver's expired licence and PERM-001
         * gives them only Own/Assigned scope on the trip itself.
         *
         * ONE KEY FOR ALL THREE WRITE ACTIONS — generate, complete and pass the
         * gate. FRS implies a split (Driver completes, Supervisor signs off,
         * Dispatcher dispatches), but with Driver unimplementable the actor set
         * is identical for all three, and inventing a distinction the role model
         * cannot express would be exactly the over-reach FORBID-001 forbids.
         *
         * FLAGGED. Must be confirmed or replaced by Security + Architecture; see
         * docs/transport/registry-defects.md, D-21.
         */
        self::PRETRIP_VIEW => [
            self::ROLE_OWNER      => self::SCOPE_ALL,
            self::ROLE_OPERATIONS => self::SCOPE_ALL,
            self::ROLE_DISPATCHER => self::SCOPE_ALL,
            self::ROLE_ACCOUNTS   => self::SCOPE_ALL,
            self::ROLE_APPROVER   => self::SCOPE_ALL,
            self::ROLE_ADMIN      => self::SCOPE_ALL,
        ],
        self::PRETRIP_PERFORM => [
            self::ROLE_OWNER      => self::SCOPE_ALL,
            self::ROLE_OPERATIONS => self::SCOPE_ALL,
            self::ROLE_DISPATCHER => self::SCOPE_ALL,
            self::ROLE_ADMIN      => self::SCOPE_ALL,
        ],

        /* ── Dispatch — no registry row, same defect family as D-21 ─────
         *
         * Step 11's Permissions sheet has no Dispatch domain and the API
         * registry names no dispatch endpoint, so unlike API-007's
         * transport.exception.create there is not even a key to quote.
         *
         * The row below is NOT a guess about who dispatches, though — SM-TRP
         * states it outright: `dispatched` is owned by the **Dispatcher**, and
         * so is `allocated`. So this mirrors PERM-004 exactly, as pre-trip
         * does: whoever may crew a trip and certify it fit to leave is who may
         * release it. Accounts and Approver keep their N from PERM-004.
         *
         * FLAGGED for Security + Architecture, with D-18 and D-21.
         */
        self::TRIP_DISPATCH => [
            self::ROLE_OWNER      => self::SCOPE_ALL,
            self::ROLE_OPERATIONS => self::SCOPE_ALL,
            self::ROLE_DISPATCHER => self::SCOPE_ALL,
            self::ROLE_ADMIN      => self::SCOPE_ALL,
        ],

        // Mirrors PERM-001.
        self::ORDER_VIEW => [
            self::ROLE_OWNER      => self::SCOPE_ALL,
            self::ROLE_OPERATIONS => self::SCOPE_ALL,
            self::ROLE_DISPATCHER => self::SCOPE_ALL,
            self::ROLE_ACCOUNTS   => self::SCOPE_ALL,
            self::ROLE_APPROVER   => self::SCOPE_ALL,
            self::ROLE_ADMIN      => self::SCOPE_ALL,
            self::ROLE_CUSTOMER   => self::SCOPE_OWN,
        ],
    ];

    /**
     * Sangoe role/internal_role  ->  STOS matrix role.
     *
     * THE ONE INFERRED ELEMENT IN THIS FILE. No approved document maps these two
     * vocabularies. Conservative on purpose: an unmapped identity resolves to
     * null and is denied, rather than being promoted to a role it may not hold.
     *
     * Keys are checked most specific first: internal_role, then role.
     */
    public const ROLE_MAP = [
        // internal_role (only meaningful when role = staff)
        'internal:transport_owner'      => self::ROLE_OWNER,
        'internal:transport_operations' => self::ROLE_OPERATIONS,
        'internal:transport_dispatcher' => self::ROLE_DISPATCHER,
        'internal:accounts'             => self::ROLE_ACCOUNTS,

        // role
        'role:admin'  => self::ROLE_ADMIN,
        // A generic staff member is Operations — the broadest role that still
        // cannot approve or record money. Everything narrower is opt-in above.
        'role:staff'  => self::ROLE_OPERATIONS,
        'role:client' => self::ROLE_CUSTOMER,
        // vendor / third_party_vendor / company are intentionally UNMAPPED:
        // Step 11's Supplier column grants only "Assigned" scope, and no
        // assignment concept exists until SNG-TRN-009. Mapping them now would
        // grant access with no way to narrow it.
    ];

    /**
     * Actors the folder names for an action that this system cannot express.
     *
     * D-21. FRS TRP-P0-005 and OPS §35 both put the driver at the centre of the
     * pre-trip inspection, and neither is wrong about the business — a driver
     * walking round the vehicle IS the inspection. What does not exist is a way
     * for that person to sign in: transport_drivers carries an optional
     * hr_employee_id and no user account, and no ticket before SNG-TRN-026
     * creates one.
     *
     * Kept as data so the omission is a recorded decision a later ticket can
     * search for, rather than a permission row that silently never appears.
     */
    public const UNIMPLEMENTABLE_ACTORS = [
        self::PRETRIP_PERFORM => [
            self::ROLE_DRIVER => 'FRS TRP-P0-005 and OPS §35 name the driver as the actor, but transport_drivers has no user account and cannot authenticate. Deferred with SNG-TRN-026.',
        ],
    ];

    public static function isPermission(string $key): bool
    {
        return array_key_exists($key, self::MATRIX);
    }

    public static function isRole(string $role): bool
    {
        return in_array($role, self::ROLES, true);
    }

    /** Every permission key this scaffold knows about. */
    public static function all(): array
    {
        return array_keys(self::MATRIX);
    }

    /**
     * The scope a STOS role has for a permission, or null when it has none.
     * Unknown key or unknown role both resolve to null — deny by default.
     */
    public static function scopeFor(string $permission, ?string $stosRole): ?string
    {
        if ($stosRole === null || ! self::isPermission($permission)) {
            return null;
        }

        return self::MATRIX[$permission][$stosRole] ?? null;
    }
}
