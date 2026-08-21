<?php

namespace App\Services\Customer;

use App\Models\Customer\Client;
use App\Support\Customer\CustomerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer Health (§8) and Risk (§9).
 *
 * ── How the score is built ────────────────────────────────────────────────
 * Each parameter scores 0–100 on its own terms, and the final figure is the
 * weighted average of ONLY the parameters that had data:
 *
 *     score = Σ(quality × weight) ÷ Σ(weight) × 100
 *
 * So with two measurable parameters they are worth 50 points each; with six,
 * 16.67 each. A parameter with nothing to measure is excluded from the
 * denominator rather than counted as full marks — a customer with no tickets is
 * not scoring well on ticket volume, they are simply not measurable yet, and
 * treating silence as excellence is how a dead account scores 100.
 *
 * The denominator grows on its own as Meetings and CSAT arrive, so the score is
 * always honest about what it could actually see.
 *
 * ── Why lifecycle overrides ──────────────────────────────────────────────
 * A churned customer has no open tickets, no overdue invoices and no active
 * projects — every signal reads as perfect. Lifecycle is the one fact that
 * makes the difference between "nothing is wrong" and "nothing is left".
 */
class CustomerHealthService
{
    /**
     * Default weights. Equal by design: the document calls them "configurable
     * parameters" without ranking them, and inventing a ranking here would bake
     * an opinion into every tenant. Overridable per tenant.
     */
    public const DEFAULT_WEIGHTS = [
        'payment_behaviour'   => 1.0,
        'ticket_volume'       => 1.0,
        'complaint_frequency' => 1.0,
        'project_performance' => 1.0,
        'contract_status'     => 1.0,
        'meeting_engagement'  => 1.0,
        'open_actions'        => 1.0,
        'service_performance' => 1.0,
        'revenue_trend'       => 1.0,
        'customer_feedback'   => 1.0,
    ];

    /**
     * Below this many measurable parameters the score is shown as provisional.
     * Three is the point at which one unusual signal stops dominating: with two,
     * a single perfect parameter carries half the score on its own.
     */
    public const MIN_CONFIDENT = 3;

    /** §8's four bands. */
    public const BANDS = [
        ['min' => 85, 'status' => 'Healthy',  'tone' => 'good'],
        ['min' => 70, 'status' => 'Watch',    'tone' => 'warning'],
        ['min' => 50, 'status' => 'At Risk',  'tone' => 'serious'],
        ['min' => 0,  'status' => 'Critical', 'tone' => 'critical'],
    ];

    public function score(Client $client, ?array $weights = null): array
    {
        $weights = $weights ?: self::DEFAULT_WEIGHTS;

        $params = [
            'payment_behaviour'   => $this->paymentBehaviour($client),
            'ticket_volume'       => $this->ticketVolume($client),
            'complaint_frequency' => $this->complaintFrequency($client),
            'project_performance' => $this->projectPerformance($client),
            'contract_status'     => $this->contractStatus($client),
            'meeting_engagement'  => $this->meetingEngagement($client),
            'open_actions'        => $this->openActions($client),
            'service_performance' => $this->servicePerformance($client),
            'revenue_trend'       => $this->revenueTrend($client),
            'customer_feedback'   => $this->customerFeedback($client),
        ];

        $measured = array_filter($params, fn ($p) => $p !== null);

        $sum = 0.0;
        $weightSum = 0.0;
        $breakdown = [];

        foreach ($params as $key => $p) {
            $w = (float) ($weights[$key] ?? 0);
            $available = $p !== null;

            if ($available && $w > 0) {
                $sum += ($p['score'] / 100) * $w;
                $weightSum += $w;
            }

            $breakdown[] = [
                'key'       => $key,
                'label'     => $this->label($key),
                'available' => $available,
                'score'     => $available ? round($p['score']) : null,
                // What this parameter is worth of the final 100, given how many
                // others could be measured. This is the "50 + 50" the brief asks for.
                'worth'     => null,
                'detail'    => $available ? $p['detail'] : 'No data yet',
            ];
        }

        // Fill in each parameter's share of 100 now that the denominator is known.
        foreach ($breakdown as $i => $row) {
            if ($row['available'] && $weightSum > 0) {
                $breakdown[$i]['worth'] = round(((float) ($weights[$row['key']] ?? 0) / $weightSum) * 100, 1);
            }
        }

        // Nothing measurable at all — a brand-new customer. Saying "100" would
        // be a confident claim about an account we know nothing about.
        if ($weightSum <= 0) {
            return [
                'score'       => null,
                'status'      => 'Not enough data',
                'band'        => null,
                'tone'        => 'unknown',
                'provisional' => true,
                'measured'   => 0,
                'of'         => count($params),
                'breakdown'  => $breakdown,
                'calculated_at' => now()->toIso8601String(),
            ];
        }

        $score = round(($sum / $weightSum) * 100);
        $score = $this->applyLifecycle($client, $score);

        // How much of the picture the score actually saw.
        //
        // The average is over what is measurable, which is right — but a 100
        // built from one signal is not the same claim as a 100 built from nine,
        // and showing them identically would make the weakest score the most
        // reassuring. Below MIN_CONFIDENT the figure is marked provisional so
        // the interface can say "based on 1 of 10" rather than "Healthy".
        $provisional = count($measured) < self::MIN_CONFIDENT;
        $band = $this->band($score);

        return [
            'score'       => $score,
            'status'      => $provisional ? 'Provisional' : $band['status'],
            'band'        => $band['status'],
            'tone'        => $provisional ? 'unknown' : $band['tone'],
            'provisional' => $provisional,
            'measured'   => count($measured),
            'of'         => count($params),
            'breakdown'  => $breakdown,
            'calculated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Lifecycle is a stated fact, not a signal to be averaged with others.
     * A churned account cannot be Healthy however clean its invoices look.
     */
    private function applyLifecycle(Client $client, int $score): int
    {
        return match ($client->lifecycle_status) {
            'Churned' => min($score, 20),
            'Dormant' => min($score, 55),
            default   => $score,
        };
    }

    public function band(int $score): array
    {
        foreach (self::BANDS as $b) {
            if ($score >= $b['min']) {
                return $b;
            }
        }

        return end(self::BANDS);
    }

    private function label(string $key): string
    {
        return ucwords(str_replace('_', ' ', $key));
    }

    // ── Parameters ───────────────────────────────────────────────────────
    // Each returns ['score' => 0..100, 'detail' => string] or null when there
    // is genuinely nothing to measure.

    /**
     * How promptly they pay, measured against the terms actually agreed.
     * Without payment_terms this would compare day 40 to an imagined deadline.
     */
    private function paymentBehaviour(Client $client): ?array
    {
        if (! Schema::hasTable('sales_invoices')) {
            return null;
        }

        $rows = DB::table('sales_invoices')
            ->where('tenant_id', $client->tenant_id)->where('client_id', $client->id)
            ->whereNotIn('status', ['Draft', 'Cancelled'])
            ->get(['balance', 'due_date', 'total']);

        if ($rows->isEmpty()) {
            return null;
        }

        $today = now()->toDateString();
        $overdue = $rows->filter(fn ($r) => (float) $r->balance > 0 && $r->due_date && $r->due_date < $today);

        $ratio = $rows->count() > 0 ? $overdue->count() / $rows->count() : 0;
        $score = max(0, 100 - ($ratio * 100));

        $terms = $client->payment_terms ? " on {$client->payment_terms}" : '';

        return [
            'score'  => $score,
            'detail' => $overdue->count() === 0
                ? "Nothing overdue across {$rows->count()} invoices{$terms}"
                : "{$overdue->count()} of {$rows->count()} invoices overdue{$terms}",
        ];
    }

    /** Volume relative to nothing is meaningless, so this reads open vs total. */
    private function ticketVolume(Client $client): ?array
    {
        if (! Schema::hasTable('tickets')) {
            return null;
        }

        $total = DB::table('tickets')->where('tenant_id', $client->tenant_id)
            ->where('customer_id', $client->id)->whereNull('merged_into_id')->count();

        if ($total === 0) {
            return null;
        }

        $open = DB::table('tickets')->where('tenant_id', $client->tenant_id)
            ->where('customer_id', $client->id)->whereNull('merged_into_id')
            ->whereIn('status', ['open', 'in-progress'])->count();

        $score = max(0, 100 - (($open / max(1, $total)) * 100));

        return ['score' => $score, 'detail' => "{$open} open of {$total} tickets"];
    }

    /** Needs the complaint classification that does not exist yet (§ decision 4). */
    private function complaintFrequency(Client $client): ?array
    {
        if (! Schema::hasTable('tickets') || ! Schema::hasColumn('tickets', 'type')) {
            return null;
        }

        $total = DB::table('tickets')->where('tenant_id', $client->tenant_id)
            ->where('customer_id', $client->id)->count();

        if ($total === 0) {
            return null;
        }

        $complaints = DB::table('tickets')->where('tenant_id', $client->tenant_id)
            ->where('customer_id', $client->id)->where('type', 'complaint')->count();

        return [
            'score'  => max(0, 100 - (($complaints / max(1, $total)) * 200)),
            'detail' => "{$complaints} complaints of {$total} tickets",
        ];
    }

    private function projectPerformance(Client $client): ?array
    {
        if (! Schema::hasTable('projects')) {
            return null;
        }

        $rows = DB::table('projects')
            ->where('tenant_id', $client->tenant_id)->where('customer_id', $client->id)
            ->get(['status', 'deadline', 'date_finished']);

        if ($rows->isEmpty()) {
            return null;
        }

        $today = now()->toDateString();
        $late = $rows->filter(fn ($p) => $p->deadline && ! $p->date_finished
            && $p->deadline < $today && $p->status !== 'cancelled')->count();

        return [
            'score'  => max(0, 100 - (($late / max(1, $rows->count())) * 100)),
            'detail' => $late === 0
                ? "All {$rows->count()} projects on schedule"
                : "{$late} of {$rows->count()} projects past deadline",
        ];
    }

    private function contractStatus(Client $client): ?array
    {
        $rows = DB::table('client_contracts')
            ->where('tenant_id', $client->tenant_id)->where('client_id', $client->id)
            ->get(['status', 'end_date']);

        if ($rows->isEmpty()) {
            return null;
        }

        $active = $rows->where('status', 'Active')->count();
        $expiringSoon = $rows->filter(fn ($c) => $c->status === 'Active' && $c->end_date
            && $c->end_date <= now()->addDays(30)->toDateString())->count();

        if ($active === 0) {
            return ['score' => 30, 'detail' => 'No active contract'];
        }

        return [
            'score'  => $expiringSoon > 0 ? 65 : 100,
            'detail' => $expiringSoon > 0
                ? "{$expiringSoon} of {$active} contracts expiring within 30 days"
                : "{$active} active contracts",
        ];
    }

    /** Arrives when Customer becomes a subject of the shared meeting engine. */
    private function meetingEngagement(Client $client): ?array
    {
        if (! Schema::hasTable('kickoff_meeting_subjects')) {
            return null;
        }

        $count = DB::table('kickoff_meeting_subjects')
            ->where('tenant_id', $client->tenant_id)
            ->where('subject_type', Client::class)
            ->where('subject_id', $client->id)
            ->count();

        if ($count === 0) {
            return null;
        }

        // Meeting in the last quarter reads as an engaged relationship.
        $recent = DB::table('kickoff_meeting_subjects as s')
            ->join('kickoff_meetings as m', 'm.id', '=', 's.kickoff_meeting_id')
            ->where('s.tenant_id', $client->tenant_id)
            ->where('s.subject_type', Client::class)->where('s.subject_id', $client->id)
            ->where('m.created_at', '>=', now()->subMonths(3))
            ->count();

        return [
            'score'  => $recent > 0 ? 100 : 50,
            'detail' => $recent > 0 ? "{$recent} meetings in the last 3 months" : "No meeting in 3 months ({$count} total)",
        ];
    }

    /** Action items from meetings — same dependency as meeting engagement. */
    private function openActions(Client $client): ?array
    {
        return null;
    }

    private function servicePerformance(Client $client): ?array
    {
        if (! Schema::hasTable('tickets') || ! Schema::hasColumn('tickets', 'resolved_at')) {
            return null;
        }

        $resolved = DB::table('tickets')
            ->where('tenant_id', $client->tenant_id)->where('customer_id', $client->id)
            ->whereNotNull('resolved_at')->whereNotNull('created_at')
            ->get(['created_at', 'resolved_at']);

        if ($resolved->isEmpty()) {
            return null;
        }

        $avgHours = $resolved->avg(fn ($t) =>
            max(0, (strtotime($t->resolved_at) - strtotime($t->created_at)) / 3600));

        // Under a day is excellent; a week or worse is not.
        $score = $avgHours <= 24 ? 100 : ($avgHours >= 168 ? 30 : 100 - (($avgHours - 24) / 144) * 70);

        return [
            'score'  => $score,
            'detail' => 'Average resolution '.round($avgHours).'h across '.$resolved->count().' tickets',
        ];
    }

    /**
     * Trend needs a baseline — a customer of two months has no trend, only a
     * start. relationship_started_at is what makes that distinction possible.
     */
    private function revenueTrend(Client $client): ?array
    {
        if (! Schema::hasTable('sales_invoices')) {
            return null;
        }

        $start = $client->relationship_started_at;
        if ($start && $start->diffInMonths(now()) < 6) {
            return null;
        }

        $recent = (float) DB::table('sales_invoices')
            ->where('tenant_id', $client->tenant_id)->where('client_id', $client->id)
            ->whereNotIn('status', ['Draft', 'Cancelled'])
            ->where('date', '>=', now()->subMonths(6)->toDateString())->sum('total');

        $prior = (float) DB::table('sales_invoices')
            ->where('tenant_id', $client->tenant_id)->where('client_id', $client->id)
            ->whereNotIn('status', ['Draft', 'Cancelled'])
            ->whereBetween('date', [now()->subMonths(12)->toDateString(), now()->subMonths(6)->toDateString()])
            ->sum('total');

        if ($recent <= 0 && $prior <= 0) {
            return null;
        }

        // No prior period means no trend — only a starting point. Scoring that
        // 100 would be the same mistake as counting missing data as full marks,
        // just dressed as good news: a customer's first invoice is not growth.
        if ($prior <= 0) {
            return null;
        }

        $change = (($recent - $prior) / $prior) * 100;
        $score = max(0, min(100, 60 + $change));

        return [
            'score'  => $score,
            'detail' => ($change >= 0 ? 'Up ' : 'Down ').round(abs($change)).'% on the previous 6 months',
        ];
    }

    /** No CSAT capture exists anywhere yet (§10). */
    private function customerFeedback(Client $client): ?array
    {
        return null;
    }

    // ── Risk (§9) ────────────────────────────────────────────────────────

    /**
     * Four indicators are derived from the same data as Health; Relationship
     * and Compliance are set by hand, because neither has an honest signal in
     * the system and inventing one would be worse than admitting the gap.
     */
    public function risk(Client $client): array
    {
        $toRisk = fn (?array $p) => $p === null ? null
            : ($p['score'] >= 75 ? 'Low' : ($p['score'] >= 50 ? 'Medium' : 'High'));

        $derived = [
            'payment'  => $toRisk($this->paymentBehaviour($client)),
            'contract' => $toRisk($this->contractStatus($client)),
            'service'  => $toRisk($this->servicePerformance($client) ?? $this->ticketVolume($client)),
            'project'  => $toRisk($this->projectPerformance($client)),
        ];

        $manual = [
            'relationship' => $client->risk_relationship ?? null,
            'compliance'   => $client->risk_compliance ?? null,
        ];

        $all = array_filter(array_merge($derived, $manual));
        $overall = match (true) {
            in_array('High', $all, true)   => 'High',
            in_array('Medium', $all, true) => 'Medium',
            $all === []                    => 'Unknown',
            default                        => 'Low',
        };

        return ['overall' => $overall, 'derived' => $derived, 'manual' => $manual];
    }
}
