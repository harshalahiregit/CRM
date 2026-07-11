<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrOffer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OfferService
{
    public function __construct(
        private CandidateService $candidateService,
        private OnboardingService $onboardingService,
    ) {
    }

    public function list(int $tenantId, array $filters): Collection
    {
        $query = HrOffer::with('candidate')
            ->whereHas('candidate', function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId);
            });

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }

        return $query->latest()->get();
    }

    public function create(array $data, int $tenantId): HrOffer
    {
        $candidate = HrCandidate::where('id', $data['candidate_id'])
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        // Enterprise gate (Sprint 2): an offer can only be generated AFTER the
        // candidate's onboarding has been verified and approved.
        $onboarding = $candidate->onboarding;
        if (! $onboarding || ! $onboarding->isApproved()) {
            throw new BusinessException('Onboarding verification must be approved before generating an offer for this candidate.', 422);
        }

        $offer = HrOffer::create([...$data, 'status' => 'Generated']);

        HrCandidate::where('id', $data['candidate_id'])
            ->where('tenant_id', $tenantId)
            ->update(['stage' => 'Offer']);

        Log::channel('hr')->info('Offer created', ['offer_id' => $offer->id, 'tenant_id' => $tenantId, 'candidate_id' => $candidate->id]);

        return $offer->load('candidate');
    }

    public function send(HrOffer $offer): HrOffer
    {
        $offer->update(['status' => 'Sent', 'sent_at' => now()]);

        if ($offer->candidate && $offer->candidate->email) {
            Mail::to($offer->candidate->email)->send(
                new \App\Mail\OfferLetterMail($offer)
            );
        }

        // Timeline event on the candidate.
        optional($offer->candidate)->recordAudit('Offer Sent', null, null, array_filter([
            'position'    => $offer->position,
            'offered_ctc' => $offer->offered_ctc,
        ]));

        Log::channel('hr')->info('Offer sent', ['offer_id' => $offer->id, 'tenant_id' => $offer->tenant_id]);

        return $offer;
    }

    public function updateStatus(HrOffer $offer, string $status, ?string $rejectionReason): HrOffer
    {
        $data = ['status' => $status];

        if ($status === 'Accepted') {
            $data['accepted_at'] = now();
        }
        if ($status === 'Rejected') {
            $data['rejection_reason'] = $rejectionReason;
        }

        $offer->update($data);

        // Accepted → confirm joining: reuse OnboardingService to auto-create the
        // Employee and move the candidate to Hired. Rejected/Declined → Rejected.
        if ($offer->candidate) {
            if ($status === 'Accepted') {
                $offer->candidate->recordAudit('Offer Accepted');
                $onboarding = $offer->candidate->onboarding;
                if ($onboarding) {
                    $this->onboardingService->confirmJoining($onboarding);
                } else {
                    // Legacy offers without an onboarding record: mark Selected + Hired.
                    $this->candidateService->updateDecision($offer->candidate, 'Selected');
                }
            } elseif ($status === 'Rejected') {
                $this->candidateService->updateDecision($offer->candidate, 'Rejected');
                $offer->candidate->recordAudit('Offer Declined', null, $rejectionReason);
            }
        }

        Log::channel('hr')->info('Offer status updated', ['offer_id' => $offer->id, 'tenant_id' => $offer->tenant_id, 'status' => $status]);

        return $offer;
    }

    public function destroy(HrOffer $offer): void
    {
        $offer->delete();

        Log::channel('hr')->info('Offer deleted', ['offer_id' => $offer->id, 'tenant_id' => $offer->tenant_id]);
    }
}
