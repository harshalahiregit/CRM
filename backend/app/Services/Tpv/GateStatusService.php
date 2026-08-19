<?php

namespace App\Services\Tpv;

use App\Models\Tpv\HsseIncident;
use App\Models\Tpv\TpvWorker;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorDocument;
use App\Support\Tpv\TpvOnboardingStatus;
use App\Support\Tpv\TpvWorkerStatus;
use App\Support\Vendor\VendorStatus;

/**
 * The five mandated onboarding gates (Doc 2 / Doc 4), computed per vendor from
 * the data the pipeline already produces. This formalises the gate model as a
 * read-only view — "which gates has this vendor cleared" — without rewriting the
 * working two-enum status machine. The rule the spec cares about (no skipping a
 * gate) is already enforced operationally (badge blockers, onboarding approval,
 * medical/permit gates); this makes the posture visible and auditable.
 */
class GateStatusService
{
    public function compute(Vendor $vendor): array
    {
        $gates = [
            $this->commercialLegal($vendor),
            $this->workforce($vendor),
            $this->safety($vendor),
            $this->internalApproval($vendor),
            $this->activation($vendor),
        ];

        $cleared = count(array_filter($gates, fn ($g) => $g['passed']));

        return [
            'vendor_id'    => $vendor->id,
            'gates'        => $gates,
            'cleared'      => $cleared,
            'total'        => count($gates),
            'all_cleared'  => $cleared === count($gates),
        ];
    }

    private function commercialLegal(Vendor $vendor): array
    {
        $required = VendorDocument::requiredFor($vendor->vendor_type ?? 'standard');
        if (empty($required)) {
            return $this->gate('Commercial / Legal', true, 'No statutory documents required');
        }
        $docs = $vendor->documents()->where('status', 'Approved')->get(['type', 'expires_at']);
        $valid = 0;
        foreach ($required as $type) {
            if ($docs->first(fn ($d) => $d->type === $type && (! $d->expires_at || $d->expires_at->gte(now())))) {
                $valid++;
            }
        }

        return $this->gate('Commercial / Legal', $valid === count($required), "{$valid}/".count($required).' statutory documents current');
    }

    private function workforce(Vendor $vendor): array
    {
        $total = TpvWorker::where('vendor_id', $vendor->id)->count();
        if ($total === 0) {
            return $this->gate('Workforce', false, 'No workforce registered');
        }
        $active = TpvWorker::where('vendor_id', $vendor->id)->where('status', TpvWorkerStatus::ACTIVE)->count();

        return $this->gate('Workforce', $active > 0, "{$active}/{$total} workers badge-active");
    }

    private function safety(Vendor $vendor): array
    {
        $graveOpen = HsseIncident::where('vendor_id', $vendor->id)
            ->where('status', '!=', 'Closed')
            ->where(fn ($q) => $q->whereIn('severity', ['Serious', 'Fatal'])->orWhere('stop_work', true))
            ->count();

        return $this->gate('Safety', $graveOpen === 0, $graveOpen === 0 ? 'No open grave incidents / stop-works' : "{$graveOpen} open grave incident(s)");
    }

    private function internalApproval(Vendor $vendor): array
    {
        $ob = $vendor->tpvOnboarding;
        $approved = $ob && $ob->status === TpvOnboardingStatus::APPROVED;

        return $this->gate('Internal Approval', $approved, $approved ? 'Onboarding approved' : 'Onboarding not yet approved');
    }

    private function activation(Vendor $vendor): array
    {
        $active = $vendor->status === VendorStatus::ACTIVE;

        return $this->gate('Activation', $active, $active ? 'Vendor Active — site access granted' : 'Vendor '.$vendor->status_label);
    }

    private function gate(string $name, bool $passed, string $detail): array
    {
        return ['gate' => $name, 'passed' => $passed, 'detail' => $detail];
    }
}
