<?php

namespace App\Services\Tpv;

use App\Exceptions\BusinessException;
use App\Models\Tpv\TpvGateAttendance;
use App\Models\Tpv\TpvGateScan;
use App\Models\Tpv\TpvSafetyStrike;
use App\Models\Tpv\TpvWorker;
use App\Support\Tpv\GateDecision;
use App\Support\Tpv\StrikeSeverity;
use App\Support\Tpv\TpvMedicalFitness;
use App\Support\Tpv\TpvWorkerStatus;
use App\Support\Vendor\VendorStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The site gate. Resolves a badge QR token, decides whether the holder may enter,
 * and records attendance.
 *
 * The gate re-validates everything live rather than trusting the badge: a vendor
 * can be blacklisted, a medical can lapse, or strikes can accumulate long after
 * the card was printed.
 */
class GateScanService
{
    /**
     * Resolve a badge token. Terminated workers have their token destroyed, so a
     * retained card simply stops resolving.
     */
    public function resolve(string $token): TpvWorker
    {
        // Not tenant-scoped: the token itself identifies the worker globally.
        $worker = TpvWorker::with(['vendor:id,company_name,status', 'medical'])
            ->where('qr_token', $token)->first();

        if (! $worker) {
            // Unknown tokens can't be attributed to a tenant, so they're logged to
            // the channel rather than the per-tenant gate log.
            Log::channel('tpv')->warning('Gate scan: unknown badge token presented', [
                'token_prefix' => substr($token, 0, 6), 'ip' => request()->ip(),
            ]);
            throw new BusinessException('Unknown or inactive badge.', 404);
        }

        return $worker;
    }

    /**
     * The decision engine — every reason the holder may or may not enter.
     * Deny reasons win over warnings; with neither, entry is clear.
     */
    public function evaluate(TpvWorker $worker): array
    {
        $deny = [];
        $warn = [];

        // ── Worker standing ──
        if ($worker->status === TpvWorkerStatus::TERMINATED) {
            $deny[] = 'Site access has been terminated.';
        } elseif ($worker->status === TpvWorkerStatus::SUSPENDED) {
            $deny[] = 'Site access is suspended.';
        } elseif ($worker->status !== TpvWorkerStatus::ACTIVE) {
            $deny[] = 'No active badge on file.';
        }

        // ── Badge validity ──
        if ($worker->badge_valid_until) {
            if ($worker->badge_valid_until->isPast()) {
                $deny[] = 'Badge expired on '.$worker->badge_valid_until->format('d M Y').'.';
            } elseif ($worker->badge_valid_until->lt(now()->addDays(30))) {
                $warn[] = 'Badge expires on '.$worker->badge_valid_until->format('d M Y').'.';
            }
        }

        // ── Employing vendor, re-checked live ──
        // A vendor can be put on hold or blacklisted long after this badge printed.
        $vendor = $worker->vendor;
        if (! $vendor) {
            $deny[] = 'Employing vendor no longer exists.';
        } elseif ($vendor->status !== VendorStatus::ACTIVE) {
            $deny[] = "Employing vendor {$vendor->company_name} is {$vendor->status_label}.";
        }

        // ── Safety strikes ──
        $strikes = $this->activeStrikeCount($worker);
        if ($strikes >= StrikeSeverity::LIMIT) {
            // Belt and braces: the engine terminates at the limit, so reaching here
            // means something bypassed it.
            $deny[] = "{$strikes} active safety strikes.";
        } elseif ($strikes >= StrikeSeverity::WARN_AT) {
            $warn[] = "Final warning — {$strikes} active safety strikes.";
        }

        // ── Medical currency + restrictions ──
        $medical = $worker->medical;
        if ($medical) {
            if ($medical->exam_date && $medical->exam_date->lt(now()->subYear())) {
                $warn[] = 'Medical examination is over a year old.';
            }
            if ($medical->fitness_status === TpvMedicalFitness::FIT_WITH_RESTRICTIONS) {
                $warn[] = 'Fit with restrictions'.($medical->restrictions ? ': '.$medical->restrictions : '.');
            }
        }

        $decision = $deny !== [] ? GateDecision::DENY : ($warn !== [] ? GateDecision::WARN : GateDecision::ADMIT);

        return [
            'decision' => $decision,
            'reasons'  => $deny !== [] ? $deny : ($warn !== [] ? $warn : ['Clear to enter.']),
            'strikes'  => $strikes,
        ];
    }

    /** Read-only scan — shows the pass card without touching attendance. */
    public function scan(string $token, Request $request): array
    {
        $worker = $this->resolve($token);
        $eval   = $this->evaluate($worker);

        $this->logScan($worker, $eval, 'scan', $request);

        return $this->card($worker, $eval);
    }

    /** Record entry. Refused if the decision does not permit entry. */
    public function checkIn(string $token, Request $request): array
    {
        $worker = $this->resolve($token);
        $eval   = $this->evaluate($worker);
        $this->logScan($worker, $eval, 'check_in', $request);

        if (! GateDecision::permitsEntry($eval['decision'])) {
            throw new BusinessException('Entry refused — '.implode(' ', $eval['reasons']));
        }

        $today = now()->toDateString();
        $att = TpvGateAttendance::where('tenant_id', $worker->tenant_id)
            ->where('tpv_worker_id', $worker->id)->whereDate('work_date', $today)->first();

        if ($att && $att->on_site) {
            throw new BusinessException('Already checked in at '.$att->check_in_at->format('H:i').'.');
        }
        if ($att && $att->check_out_at) {
            // One session per day for now — re-entry after checkout needs the
            // multi-session model, which isn't built.
            throw new BusinessException('Already checked out today at '.$att->check_out_at->format('H:i').'.');
        }

        $att = TpvGateAttendance::updateOrCreate(
            ['tenant_id' => $worker->tenant_id, 'tpv_worker_id' => $worker->id, 'work_date' => $today],
            ['check_in_at' => now(), 'check_in_gate' => $request->input('gate')]
        );

        Log::channel('tpv')->info('Gate check-in', [
            'worker_id' => $worker->id, 'tenant_id' => $worker->tenant_id, 'decision' => $eval['decision'],
        ]);

        return $this->card($worker->fresh(['vendor', 'medical']), $eval, $att->fresh());
    }

    /**
     * Record exit. Deliberately not gated on the entry decision — someone whose
     * access lapsed while on site still has to be able to leave.
     */
    public function checkOut(string $token, Request $request): array
    {
        $worker = $this->resolve($token);
        $eval   = $this->evaluate($worker);
        $this->logScan($worker, $eval, 'check_out', $request);

        $att = TpvGateAttendance::where('tenant_id', $worker->tenant_id)
            ->where('tpv_worker_id', $worker->id)->whereDate('work_date', now()->toDateString())->first();

        if (! $att || ! $att->check_in_at) {
            throw new BusinessException('No check-in recorded today.');
        }
        if ($att->check_out_at) {
            throw new BusinessException('Already checked out at '.$att->check_out_at->format('H:i').'.');
        }

        $att->update([
            'check_out_at'    => now(),
            'check_out_gate'  => $request->input('gate'),
            'minutes_on_site' => $att->check_in_at->diffInMinutes(now()),
        ]);

        Log::channel('tpv')->info('Gate check-out', [
            'worker_id' => $worker->id, 'tenant_id' => $worker->tenant_id, 'minutes' => $att->minutes_on_site,
        ]);

        return $this->card($worker->fresh(['vendor', 'medical']), $eval, $att->fresh());
    }

    /* ── Reads (authed side) ────────────────────────────────────────────── */

    /** The attendance roster for a date — who was on site, and who still is. */
    public function roster(int $tenantId, ?string $date = null): array
    {
        $date = $date ?: now()->toDateString();

        $rows = TpvGateAttendance::forTenant($tenantId)->forDate($date)
            ->with(['worker:id,name,worker_code,designation,vendor_id,status', 'worker.vendor:id,company_name'])
            ->orderByDesc('check_in_at')->get();

        return [
            'date'    => $date,
            'summary' => [
                'total'    => $rows->count(),
                'on_site'  => $rows->where('on_site', true)->count(),
                'departed' => $rows->whereNotNull('check_out_at')->count(),
                'total_minutes' => (int) $rows->sum('minutes_on_site'),
            ],
            'rows' => $rows,
        ];
    }

    /** Per-worker attendance history. */
    public function attendanceFor(TpvWorker $worker, int $days = 30): \Illuminate\Support\Collection
    {
        return $worker->attendances()
            ->where('work_date', '>=', now()->subDays($days)->toDateString())
            ->get();
    }

    /** The gate log — every badge presented, admitted or refused. */
    public function gateLog(int $tenantId, array $filters): \Illuminate\Support\Collection
    {
        $query = TpvGateScan::forTenant($tenantId)
            ->with(['worker:id,name,worker_code,designation']);

        if (! empty($filters['decision']) && $filters['decision'] !== 'All') {
            $query->where('decision', $filters['decision']);
        }
        if (! empty($filters['worker_id'])) {
            $query->where('tpv_worker_id', $filters['worker_id']);
        }
        if (! empty($filters['date'])) {
            $query->whereDate('scanned_at', $filters['date']);
        }

        return $query->latest('scanned_at')->limit(200)->get();
    }

    public function gateStats(int $tenantId): array
    {
        $today = now()->toDateString();
        $scans = TpvGateScan::forTenant($tenantId)->whereDate('scanned_at', $today);

        return [
            'scans_today'   => (clone $scans)->count(),
            'denied_today'  => (clone $scans)->denied()->count(),
            'on_site_now'   => TpvGateAttendance::forTenant($tenantId)->forDate($today)->onSite()->count(),
            'checked_in_today' => TpvGateAttendance::forTenant($tenantId)->forDate($today)->count(),
        ];
    }

    /* ── Internals ──────────────────────────────────────────────────────── */

    public function activeStrikeCount(TpvWorker $worker): int
    {
        return TpvSafetyStrike::where('tpv_worker_id', $worker->id)->active()->count();
    }

    /**
     * The pass card the guard sees. Minimal PII — enough to match the person to
     * the badge and act in an emergency, nothing more.
     */
    private function card(TpvWorker $worker, array $eval, ?TpvGateAttendance $att = null): array
    {
        $att = $att ?: TpvGateAttendance::where('tenant_id', $worker->tenant_id)
            ->where('tpv_worker_id', $worker->id)->whereDate('work_date', now()->toDateString())->first();

        return [
            'decision'       => $eval['decision'],
            'decision_label' => GateDecision::label($eval['decision']),
            'permits_entry'  => GateDecision::permitsEntry($eval['decision']),
            'reasons'        => $eval['reasons'],
            'worker' => [
                'name'         => $worker->name,
                'worker_code'  => $worker->worker_code,
                'designation'  => $worker->designation,
                'photo_path'   => $worker->photo_path,
                'blood_group'  => $worker->blood_group,   // needed at the gate in an emergency
                'vendor'       => $worker->vendor?->company_name,
                'badge_number' => $worker->badge_number,
                'valid_until'  => $worker->badge_valid_until?->toDateString(),
                'status'       => $worker->status,
            ],
            'strikes' => ['active' => $eval['strikes'], 'limit' => StrikeSeverity::LIMIT],
            'attendance' => $att ? [
                'on_site'      => $att->on_site,
                'check_in_at'  => $att->check_in_at?->toIso8601String(),
                'check_out_at' => $att->check_out_at?->toIso8601String(),
                'duration'     => $att->duration_label,
            ] : null,
        ];
    }

    private function logScan(TpvWorker $worker, array $eval, string $action, Request $request): void
    {
        TpvGateScan::create([
            'tenant_id'     => $worker->tenant_id,
            'tpv_worker_id' => $worker->id,
            'decision'      => $eval['decision'],
            'reasons'       => $eval['reasons'],
            'action'        => $action,
            'gate'          => $request->input('gate'),
            'ip'            => $request->ip(),
            'user_agent'    => substr((string) $request->userAgent(), 0, 255),
            'scanned_at'    => now(),
        ]);
    }
}
