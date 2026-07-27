<?php

namespace App\Services\Tpv;

use App\Models\Shared\KickoffMeeting;
use App\Models\Tpv\TpvOnboarding;
use App\Models\Vendor\Vendor;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the Kickoff MOM PDF for a TPV onboarding from the shared Kickoff Meeting engine.
 */
class KickoffPdfService
{
    private const DISK = 'kickoff_docs';

    /** Find the latest completed & sent Kickoff Meeting for an onboarding. */
    public function findKickoffMeeting(TpvOnboarding $onboarding): ?KickoffMeeting
    {
        if ($onboarding->kickoff_meeting_id) {
            $meeting = KickoffMeeting::forTenant($onboarding->tenant_id)
                ->where('id', $onboarding->kickoff_meeting_id)
                ->where('status', 'Completed')
                ->whereNotNull('mom_path')
                ->first();

            if ($meeting) {
                return $meeting;
            }
        }

        return KickoffMeeting::forTenant($onboarding->tenant_id)
            ->where(function ($q) use ($onboarding) {
                $q->where(function ($q2) use ($onboarding) {
                    $q2->whereIn('kickoffable_type', [Vendor::class, 'vendor', 'App\Models\Vendor\Vendor'])
                       ->where('kickoffable_id', $onboarding->vendor_id);
                })->orWhere(function ($q2) use ($onboarding) {
                    $q2->whereIn('kickoffable_type', [TpvOnboarding::class, 'onboarding', 'App\Models\Tpv\TpvOnboarding'])
                       ->where('kickoffable_id', $onboarding->id);
                });
            })
            ->where('status', 'Completed')
            ->whereNotNull('mom_path')
            ->latest()
            ->first();
    }

    /** Inline (viewer-friendly) streamed response for the Kickoff MOM PDF. */
    public function stream(TpvOnboarding $onboarding): StreamedResponse
    {
        $meeting = $this->findKickoffMeeting($onboarding);

        abort_unless(
            $meeting && $meeting->mom_path && Storage::disk(self::DISK)->exists($meeting->mom_path),
            404,
            'Kickoff meeting is not completed yet.'
        );

        return Storage::disk(self::DISK)->response(
            $meeting->mom_path,
            "Kickoff-MOM-{$meeting->id}.pdf",
            ['Content-Type' => 'application/pdf'],
            'inline'
        );
    }
}
