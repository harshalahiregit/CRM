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

    /**
     * Every spelling a morph column has been written with. Older rows carry the
     * short alias, newer ones the FQCN; both must match or a vendor's own meeting
     * silently stops resolving. Named here rather than inlined so the kickoffable
     * columns and the subjects pivot cannot drift apart.
     */
    private const VENDOR_TYPES = [Vendor::class, 'vendor', 'App\Models\Vendor\Vendor'];

    private const ONBOARDING_TYPES = [TpvOnboarding::class, 'onboarding', 'App\Models\Tpv\TpvOnboarding'];

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
                    $q2->whereIn('kickoffable_type', self::VENDOR_TYPES)
                       ->where('kickoffable_id', $onboarding->vendor_id);
                })->orWhere(function ($q2) use ($onboarding) {
                    $q2->whereIn('kickoffable_type', self::ONBOARDING_TYPES)
                       ->where('kickoffable_id', $onboarding->id);
                // A multi-vendor kickoff names ONE vendor on kickoffable_*; the rest
                // live only in kickoff_meeting_subjects. Matching the columns alone
                // meant every secondary vendor was told "Kickoff meeting is not
                // completed yet" while the primary read the same minutes fine.
                })->orWhereHas('subjects', function ($q2) use ($onboarding) {
                    $q2->where(function ($q3) use ($onboarding) {
                        $q3->whereIn('subject_type', self::VENDOR_TYPES)
                           ->where('subject_id', $onboarding->vendor_id);
                    })->orWhere(function ($q3) use ($onboarding) {
                        $q3->whereIn('subject_type', self::ONBOARDING_TYPES)
                           ->where('subject_id', $onboarding->id);
                    });
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
