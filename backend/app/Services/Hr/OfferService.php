<?php

namespace App\Services\Hr;

use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrOffer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OfferService
{
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

        Log::channel('hr')->info('Offer sent', ['offer_id' => $offer->id, 'tenant_id' => $offer->tenant_id]);

        return $offer;
    }

    public function updateStatus(HrOffer $offer, string $status, ?string $rejectionReason): HrOffer
    {
        $data = ['status' => $status];

        if ($status === 'Accepted') {
            $data['accepted_at'] = now();
            HrCandidate::where('id', $offer->candidate_id)->update(['stage' => 'Hired', 'final_decision' => 'Selected']);
        }

        if ($status === 'Rejected') {
            $data['rejection_reason'] = $rejectionReason;
            HrCandidate::where('id', $offer->candidate_id)->update(['final_decision' => 'Rejected']);
        }

        $offer->update($data);

        Log::channel('hr')->info('Offer status updated', ['offer_id' => $offer->id, 'tenant_id' => $offer->tenant_id, 'status' => $status]);

        return $offer;
    }

    public function destroy(HrOffer $offer): void
    {
        $offer->delete();

        Log::channel('hr')->info('Offer deleted', ['offer_id' => $offer->id, 'tenant_id' => $offer->tenant_id]);
    }
}
