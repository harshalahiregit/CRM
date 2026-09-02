<?php

namespace App\Services\Purchase;

use App\Models\Purchase\PurchaseGateEvent;
use App\Models\Purchase\PurchaseGateScan;
use App\Models\Purchase\PurchaseWorker;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The Purchase site gate — mirror of TPV's gate services on Purchase's tables.
 *
 * PurchaseWorkforceService::gateDecision() could already say whether a worker
 * may enter; nothing recorded that the question had been asked. This turns that
 * verdict into a logged crossing, which is what makes attendance and the gate
 * log possible at all.
 *
 * The decision is taken from gateDecision() and then WRITTEN DOWN with its
 * reasons, rather than re-derived when the log is read. A worker admitted last
 * week under rules that have since changed was still admitted.
 */
class PurchaseGateService
{
    public function __construct(private PurchaseWorkforceService $workforce)
    {
    }

    /**
     * Scan a worker's badge. Records the crossing and returns the verdict.
     *
     * `action` (in/out) is taken from the caller when given, and otherwise
     * inferred by alternating from the worker's last scan — a gate that has to
     * be told the direction every time gets it wrong the first time someone is
     * in a hurry.
     */
    public function scan(PurchaseWorker $worker, array $data = [], ?Request $request = null): array
    {
        $decision = $this->workforce->gateDecision($worker);

        // gateDecision() answers {admit, reason, warning?} — a single reason, not
        // a list. Both are kept: a worker can be admitted WITH a warning (the
        // PPE 'warn' mode), and a log that dropped the warning would show a
        // clean entry for someone who walked in with no kit.
        $allowed = (bool) ($decision['admit'] ?? false);
        $reasons = array_values(array_filter([
            $decision['reason'] ?? null,
            $decision['warning'] ?? null,
        ]));

        $scan = PurchaseGateScan::create([
            'tenant_id'          => $worker->tenant_id,
            'purchase_vendor_id' => $worker->purchase_vendor_id,
            'purchase_worker_id' => $worker->id,
            'decision'           => $allowed ? PurchaseGateScan::ALLOW : PurchaseGateScan::DENY,
            'reasons'            => $reasons ?: null,
            'action'             => $data['action'] ?? $this->inferAction($worker),
            'gate'               => $data['gate'] ?? null,
            'ip'                 => $request?->ip(),
            'user_agent'         => $request?->userAgent(),
            'scanned_at'         => $data['scanned_at'] ?? now(),
        ]);

        Log::channel('purchase')->info('Purchase gate scan', [
            'worker_id' => $worker->id, 'decision' => $scan->decision, 'action' => $scan->action,
        ]);

        return ['scan' => $scan, 'decision' => $decision];
    }

    /**
     * IN unless the last scan was itself an admitted IN.
     *
     * Only ALLOWED scans move the direction: a refusal means the person never
     * crossed, so counting it would flip the next genuine entry to an exit and
     * leave the roster showing someone on site who was turned away.
     */
    private function inferAction(PurchaseWorker $worker): string
    {
        $last = PurchaseGateScan::where('purchase_worker_id', $worker->id)
            ->where('decision', PurchaseGateScan::ALLOW)
            ->latest('scanned_at')->latest('id')
            ->first();

        return ($last && $last->action === 'in') ? 'out' : 'in';
    }

    /** The gate log — every crossing, newest first, filterable. */
    public function log(int $tenantId, array $filters = [])
    {
        $q = PurchaseGateScan::forTenant($tenantId)
            ->with(['worker:id,full_name,worker_code,designation,purchase_vendor_id', 'vendor:id,company_name']);

        if (! empty($filters['vendor_id'])) {
            $q->where('purchase_vendor_id', (int) $filters['vendor_id']);
        }
        if (! empty($filters['worker_id'])) {
            $q->where('purchase_worker_id', (int) $filters['worker_id']);
        }
        if (! empty($filters['decision'])) {
            $q->where('decision', $filters['decision']);
        }
        if (! empty($filters['from'])) {
            $q->whereDate('scanned_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->whereDate('scanned_at', '<=', $filters['to']);
        }

        return $q->latest('scanned_at')->latest('id')->limit((int) ($filters['limit'] ?? 500))->get();
    }

    /** Gate counters for the day — what the log page shows above the table. */
    public function stats(int $tenantId, ?string $date = null): array
    {
        $date = $date ?: now()->toDateString();
        $base = fn () => PurchaseGateScan::forTenant($tenantId)->whereDate('scanned_at', $date);

        $allowed = (clone $base())->where('decision', PurchaseGateScan::ALLOW);

        return [
            'date'     => $date,
            'scans'    => $base()->count(),
            'allowed'  => (clone $allowed)->count(),
            'denied'   => $base()->where('decision', PurchaseGateScan::DENY)->count(),
            // Distinct people admitted today, and how many are still inside —
            // the roster question the gate exists to answer.
            'on_site'  => $this->onSite($tenantId, $date)->count(),
            'entered'  => (clone $allowed)->where('action', 'in')->distinct('purchase_worker_id')->count('purchase_worker_id'),
        ];
    }

    /**
     * Who is currently on site: workers whose most recent ALLOWED scan today was
     * an entry. Computed from the log rather than a flag on the worker, so it
     * cannot drift out of step with what the gate actually recorded.
     */
    public function onSite(int $tenantId, ?string $date = null)
    {
        $date = $date ?: now()->toDateString();

        return PurchaseGateScan::forTenant($tenantId)
            ->whereDate('scanned_at', $date)
            ->where('decision', PurchaseGateScan::ALLOW)
            ->with('worker:id,full_name,worker_code,designation,purchase_vendor_id')
            ->latest('scanned_at')->latest('id')
            ->get()
            ->groupBy('purchase_worker_id')
            ->map(fn ($scans) => $scans->first())
            ->filter(fn ($scan) => $scan->action === 'in')
            ->values();
    }

    /**
     * One worker's attendance — crossings grouped into days, with the first
     * entry, last exit and hours on site.
     */
    public function workerAttendance(PurchaseWorker $worker, array $filters = []): array
    {
        $q = PurchaseGateScan::where('purchase_worker_id', $worker->id)
            ->where('decision', PurchaseGateScan::ALLOW);

        if (! empty($filters['from'])) {
            $q->whereDate('scanned_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->whereDate('scanned_at', '<=', $filters['to']);
        }

        $days = $q->orderBy('scanned_at')->get()
            ->groupBy(fn ($s) => optional($s->scanned_at)->toDateString())
            ->map(function ($scans, $day) {
                $in  = $scans->firstWhere('action', 'in');
                $out = $scans->where('action', 'out')->last();
                // Hours are only meaningful with both ends. A day with an entry
                // and no exit is left null rather than counted to "now", which
                // would grow all evening for someone who simply forgot to scan out.
                $hours = ($in && $out && $out->scanned_at->gt($in->scanned_at))
                    ? round($in->scanned_at->diffInMinutes($out->scanned_at) / 60, 2)
                    : null;

                return [
                    'date'       => $day,
                    'first_in'   => optional($in?->scanned_at)->toIso8601String(),
                    'last_out'   => optional($out?->scanned_at)->toIso8601String(),
                    'crossings'  => $scans->count(),
                    'hours'      => $hours,
                ];
            })->values();

        return [
            'worker' => $worker->only(['id', 'full_name', 'worker_code', 'designation']),
            'days'   => $days,
            'totals' => [
                'days_present' => $days->count(),
                'hours'        => round($days->sum('hours'), 2),
            ],
        ];
    }

    /* ── Non-person crossings (TPV §20) ──────────────────────────────────── */

    public function events(int $tenantId, array $filters = [])
    {
        $q = PurchaseGateEvent::forTenant($tenantId)->with('vendor:id,company_name');

        foreach (['event_kind', 'direction'] as $f) {
            if (! empty($filters[$f])) {
                $q->where($f, $filters[$f]);
            }
        }
        if (! empty($filters['vendor_id'])) {
            $q->where('purchase_vendor_id', (int) $filters['vendor_id']);
        }
        if (! empty($filters['from'])) {
            $q->whereDate('occurred_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->whereDate('occurred_at', '<=', $filters['to']);
        }

        return $q->latest('occurred_at')->latest('id')->limit((int) ($filters['limit'] ?? 500))->get();
    }

    public function recordEvent(int $tenantId, array $data, ?User $actor = null): PurchaseGateEvent
    {
        return PurchaseGateEvent::create(array_merge($data, [
            'tenant_id'   => $tenantId,
            'occurred_at' => $data['occurred_at'] ?? now(),
            'recorded_by' => $actor?->id,
        ]));
    }
}
