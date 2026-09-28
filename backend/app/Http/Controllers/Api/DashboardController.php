<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auth\StaffPermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The first screen after login.
 *
 * This used to return seven hard zeros with a comment saying real queries would
 * be added as modules were built — and the frontend covered for it by swapping
 * in invented figures (128 contacts, 34 deals, ₹284,500 pipeline, 68% win rate)
 * whenever the counts came back zero. So a brand-new tenant, and any quiet one,
 * was shown someone else's imaginary business on the screen they trust most.
 *
 * The modules now exist, so the numbers are real. They are read through guarded
 * query builders rather than another module's Eloquent models: this controller
 * is shared ground, and a tenant whose deployment has not migrated a given
 * module should see that figure as zero rather than a 500 on the landing page.
 *
 * Anything that cannot be computed honestly is not returned at all — see
 * `available` below, which tells the UI which tiles it may render. An absent
 * number stays absent instead of becoming a plausible one.
 */
class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        $data = [
            'contacts_count'     => $this->contacts($tenantId),
            'open_deals'         => $this->openDeals($tenantId),
            'tasks_due_today'    => $this->tasksDueToday($tenantId),
            'overdue_invoices'   => $this->overdueInvoices($tenantId),
            'pipeline_value'     => $this->pipelineValue($tenantId),
            'win_rate'           => $this->winRate($tenantId),
            'revenue_this_month' => $this->revenueThisMonth($tenantId),
            'revenue_by_month'   => $this->revenueByMonth($tenantId),
            'recent_activity'    => $this->recentActivity($tenantId, $request->user(), $request),
        ];

        // Whose activity this feed is showing, and whether the reader is
        // allowed to widen it. The feed used to be silently company-wide for
        // anyone entitled to it, with nothing on screen saying so and no way to
        // narrow it back to yourself.
        $data['last_sign_in'] = $this->lastSignIn($request);

        $data['activity_scope'] = [
            'showing'  => $this->activityScope($request),
            'can_widen' => app(StaffPermissionService::class)->scope($request->user(), 'reports') === 'global',
        ];

        // Which figures this deployment can actually produce. The UI hides a
        // tile whose module is absent rather than drawing a confident zero.
        $data['available'] = [
            'contacts'  => Schema::hasTable('client_contacts'),
            'deals'     => Schema::hasTable('leads'),
            'tasks'     => Schema::hasTable('tasks'),
            'invoices'  => Schema::hasTable('sales_invoices'),
            'revenue'   => Schema::hasTable('sales_payments'),
        ];

        return response()->json([
            'status'  => 'success',
            'message' => 'Dashboard data',
            'data'    => $data,
        ]);
    }

    /**
     * The tenant's last few audited actions.
     *
     * The dashboard card was a hard-coded list — "New deal created / Acme Corp —
     * $12,500", "Deal won 🎉 / Initech Partnership" — identical for every tenant
     * on every load. There is a real polymorphic audit trail; this reads it.
     *
     * The record type is derived from the model class rather than stored, so a
     * new auditable model appears here without touching this method.
     *
     * @return array<int, array{action: string, description: string, at: ?string}>
     */
    /**
     * The activity feed — SIR-000044.
     *
     * It filtered on tenant_id alone, so every authenticated user read the whole
     * company's audit trail on the landing page, actor_name included: who edited
     * which invoice, who touched whose employee record, across every module. On a
     * shared tenant that is an org chart and a work diary handed to anybody with
     * a login.
     *
     * ── WHO MAY SEE EVERYONE ─────────────────────────────────────────────────
     * StaffPermissionService::scope() exists for exactly this decision — its own
     * docblock says 'global' sees the company and 'own' sees their own records,
     * and that a report or a list is what should branch on it. The audit trail
     * spans every module, so `reports` is the module that governs it.
     *
     * Unlike the lead-edit gate, this one does NOT grandfather an unconfigured
     * grid. Narrowing to your own actions is not a lockout — it is what the
     * report asked for, and it is the safe direction to fail in. Nobody loses
     * work they do daily; they lose sight of other people's, which they were
     * never meant to have.
     */
    /**
     * The sign-in BEFORE this one — what "was that me?" actually needs.
     *
     * The login screen used to answer this with two string literals, telling
     * everyone who ever opened it that they last signed in from Pune on Chrome.
     * The honest version is here instead, after sign-in, where the caller is
     * known: it reports the previous session's own recorded device, browser
     * and IP, and reports nothing where nothing was recorded.
     *
     * Deliberately no city. Turning an IP into a place needs a geo-IP service
     * this deployment does not have, and guessing one is how "Pune" got here
     * in the first place. An IP is coarse but true.
     *
     * Null on a first-ever sign-in, and null where session tracking is not
     * migrated — the UI then shows nothing rather than an empty shell.
     */
    private function lastSignIn(Request $request): ?array
    {
        if (! Schema::hasTable('user_sessions')) {
            return null;
        }

        $user = $request->user();

        // Skip the session doing the asking: the newest row is the one that was
        // created by the login currently on screen, and reporting it back as
        // "last sign-in" would just be the clock.
        $currentTokenId = $user->currentAccessToken()?->id;

        $row = DB::table('user_sessions')
            ->where('user_id', $user->id)
            ->when($currentTokenId, fn ($q) => $q->where(fn ($w) => $w->whereNull('token_id')->orWhere('token_id', '!=', $currentTokenId)))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->first(['device', 'browser', 'ip', 'created_at']);

        if (! $row) {
            return null;
        }

        return [
            'at'      => $row->created_at,
            'device'  => $row->device ?: null,
            'browser' => $row->browser ?: null,
            'ip'      => $row->ip ?: null,
        ];
    }

    /**
     * Which feed the caller asked for. 'mine' unless they explicitly asked for
     * everyone's — the default is the narrow one, because a landing page should
     * not open on other people's work.
     */
    private function activityScope(Request $request): string
    {
        return $request->query('activity_scope') === 'all' ? 'all' : 'mine';
    }

    private function recentActivity(int $tenantId, $user, ?Request $request = null, int $limit = 6): array
    {
        if (! Schema::hasTable('audit_logs')) {
            return [];
        }

        // Two separate questions, and they were being answered as one.
        //
        // Being ENTITLED to the company's activity is not the same as ASKING
        // for it. The feed widened itself the moment someone had the report
        // scope, so an admin opening the dashboard was shown the whole
        // company's actions — everyone's, unasked, on the first screen after
        // login, with no way back to their own.
        //
        // Now the entitlement only decides whether "Everyone" is available at
        // all; the request decides what is actually shown, and it shows the
        // reader's own work unless they say otherwise.
        $entitled = app(StaffPermissionService::class)->scope($user, 'reports') === 'global';
        $wantsAll = $request !== null && $this->activityScope($request) === 'all';
        $global   = $entitled && $wantsAll;

        return DB::table('audit_logs')
            ->where('tenant_id', $tenantId)
            // Own actions only, unless entitled to the whole tenant's AND asking
            // for them. Rows with no actor_id are system writes and belong to
            // nobody, so a scoped reader does not see them either.
            ->when(! $global, fn ($q) => $q->where('actor_id', $user->id))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit($limit)
            ->get(['action', 'actor_name', 'auditable_type', 'metadata', 'created_at'])
            ->map(function ($r) {
                // "App\Models\Purchase\PurchaseOrder" -> "Purchase Order"
                $short = preg_replace('/(?<!^)[A-Z]/', ' $0', class_basename($r->auditable_type ?? ''));

                // A number the record already carries reads better than its id.
                $meta = json_decode($r->metadata ?? '{}', true) ?: [];
                $ref  = null;
                foreach (['number', 'po_number', 'invoice_number', 'reference', 'grn_number', 'code'] as $k) {
                    if (! empty($meta[$k])) { $ref = $meta[$k]; break; }
                }

                return [
                    'action'      => $r->action ?: 'Updated',
                    'description' => trim(implode(' · ', array_filter([
                        $ref ?: ($short ?: null),
                        $r->actor_name ?: null,
                    ]))),
                    'at' => $r->created_at,
                ];
            })
            ->all();
    }

    /** A guarded builder, or null when the module is not present. */
    private function table(string $table, int $tenantId)
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        $q = DB::table($table)->where('tenant_id', $tenantId);

        if (Schema::hasColumn($table, 'deleted_at')) {
            $q->whereNull('deleted_at');
        }

        return $q;
    }

    private function contacts(int $tenantId): int
    {
        $q = $this->table('client_contacts', $tenantId);
        if (! $q) {
            return 0;
        }
        // Deactivated contacts are still on file but are not someone you can call.
        if (Schema::hasColumn('client_contacts', 'active')) {
            $q->where('active', true);
        }

        return (int) $q->count();
    }

    /** Leads still in play: not lost, not junk, not in a won status. */
    private function openLeads(int $tenantId)
    {
        $q = $this->table('leads', $tenantId);
        if (! $q) {
            return null;
        }
        foreach (['lost', 'junk'] as $flag) {
            if (Schema::hasColumn('leads', $flag)) {
                $q->where(fn ($w) => $w->where($flag, false)->orWhereNull($flag));
            }
        }
        if (Schema::hasTable('lead_statuses') && Schema::hasColumn('lead_statuses', 'is_won_status')) {
            $won = DB::table('lead_statuses')->where('tenant_id', $tenantId)
                     ->where('is_won_status', true)->pluck('id');
            if ($won->isNotEmpty()) {
                $q->where(fn ($w) => $w->whereNotIn('status_id', $won)->orWhereNull('status_id'));
            }
        }

        return $q;
    }

    private function openDeals(int $tenantId): int
    {
        $q = $this->openLeads($tenantId);

        return $q ? (int) $q->count() : 0;
    }

    /** Sum of what the open pipeline is worth — not a projection, just the sum. */
    private function pipelineValue(int $tenantId): float
    {
        $q = $this->openLeads($tenantId);
        if (! $q || ! Schema::hasColumn('leads', 'lead_value')) {
            return 0.0;
        }

        return round((float) $q->sum('lead_value'), 2);
    }

    private function tasksDueToday(int $tenantId): int
    {
        $q = $this->table('tasks', $tenantId);
        if (! $q || ! Schema::hasColumn('tasks', 'due_date')) {
            return 0;
        }
        $q->whereDate('due_date', now()->toDateString());
        // A task that is already finished is not something due today.
        if (Schema::hasColumn('tasks', 'status')) {
            $q->whereNotIn('status', ['Completed', 'completed', 'Done', 'done', 'Cancelled', 'cancelled']);
        }

        return (int) $q->count();
    }

    private function overdueInvoices(int $tenantId): int
    {
        $q = $this->table('sales_invoices', $tenantId);
        if (! $q) {
            return 0;
        }
        // Read the same way the Sales list does: still owed, and past due.
        if (Schema::hasColumn('sales_invoices', 'due_date') && Schema::hasColumn('sales_invoices', 'balance')) {
            $q->whereDate('due_date', '<', now()->toDateString())->where('balance', '>', 0);
            if (Schema::hasColumn('sales_invoices', 'status')) {
                $q->whereNotIn('status', ['Draft', 'Paid', 'Cancelled']);
            }
        } elseif (Schema::hasColumn('sales_invoices', 'status')) {
            $q->where('status', 'Overdue');
        }

        return (int) $q->count();
    }

    private function revenueThisMonth(int $tenantId): float
    {
        $q = $this->table('sales_payments', $tenantId);
        if (! $q || ! Schema::hasColumn('sales_payments', 'amount')) {
            return 0.0;
        }
        if (Schema::hasColumn('sales_payments', 'date')) {
            $q->whereBetween('date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]);
        }

        return round((float) $q->sum('amount'), 2);
    }

    /**
     * Payments received in each of the last 12 months, oldest first.
     *
     * The dashboard's "Pipeline Overview — Revenue by month" chart was a
     * hard-coded array of twelve numbers, under a badge reading "Live". This is
     * the real series, so the badge can be true.
     *
     * @return array<int, array{label: string, value: float}>
     */
    private function revenueByMonth(int $tenantId): array
    {
        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $m = now()->startOfMonth()->subMonths($i);
            $months[] = ['label' => $m->format('M'), 'month' => $m->format('Y-m'), 'value' => 0.0];
        }

        if (! Schema::hasTable('sales_payments')
            || ! Schema::hasColumn('sales_payments', 'date')
            || ! Schema::hasColumn('sales_payments', 'amount')) {
            return $months;
        }

        // One grouped query rather than twelve — the landing page should not cost
        // a round trip per bar.
        //
        // The month expression is chosen by DRIVER, not by trying one and falling
        // back: strftime does not exist in MySQL and raises a SQL error rather
        // than returning nothing, so a "try SQLite first" version would 500 the
        // landing page in production while passing every test on SQLite.
        $ym = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', date)"
            : "DATE_FORMAT(date, '%Y-%m')";

        $rows = DB::table('sales_payments')
            ->where('tenant_id', $tenantId)
            ->whereDate('date', '>=', now()->startOfMonth()->subMonths(11)->toDateString())
            ->selectRaw("{$ym} as ym, SUM(amount) as total")
            ->groupBy('ym')
            ->pluck('total', 'ym');

        foreach ($months as &$m) {
            $m['value'] = round((float) ($rows[$m['month']] ?? 0), 2);
            unset($m['month']);
        }

        return $months;
    }

    /**
     * Won / (won + lost), as a whole percent.
     *
     * Returns null — not zero — when nothing has been decided yet. A brand-new
     * tenant has no win rate; "0%" would read as "you lose everything".
     */
    private function winRate(int $tenantId): ?int
    {
        if (! Schema::hasTable('leads') || ! Schema::hasTable('lead_statuses')
            || ! Schema::hasColumn('lead_statuses', 'is_won_status')) {
            return null;
        }

        $won = DB::table('lead_statuses')->where('tenant_id', $tenantId)
                 ->where('is_won_status', true)->pluck('id');

        $base = $this->table('leads', $tenantId);
        if (! $base) {
            return null;
        }

        $wonCount = $won->isEmpty() ? 0 : (int) (clone $base)->whereIn('status_id', $won)->count();

        $lostCount = Schema::hasColumn('leads', 'lost')
            ? (int) (clone $base)->where('lost', true)->count()
            : 0;

        $decided = $wonCount + $lostCount;

        return $decided === 0 ? null : (int) round($wonCount / $decided * 100);
    }
}
