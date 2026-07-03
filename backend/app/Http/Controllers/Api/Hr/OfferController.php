<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\HrOffer;
use App\Models\HrCandidate;
use Illuminate\Http\Request;

class OfferController extends Controller
{
    public function index(Request $request)
    {
        $query = HrOffer::with('candidate')
            ->whereHas('candidate', function($q) use ($request) {
                $q->where('tenant_id', $request->user()->tenant_id);
            });
        if ($request->filled('status') && $request->status !== 'All') {
            $query->where('status', $request->status);
        }
        return response()->json($query->latest()->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'candidate_id'    => 'required|exists:hr_candidates,id',
            'position'        => 'required|string',
            'department'      => 'required|string',
            'offered_ctc'     => 'required|numeric|min:0',
            'joining_date'    => 'required|date',
            'probation_period'=> 'nullable|string',
            'notice_period'   => 'nullable|string',
            'validity_date'   => 'nullable|date',
        ]);

        // Verify candidate belongs to user's tenant
        $candidate = HrCandidate::where('id', $validated['candidate_id'])
            ->where('tenant_id', $request->user()->tenant_id)
            ->firstOrFail();

        $offer = HrOffer::create([...$validated, 'status' => 'Generated']);

        // Move candidate to Offer stage
        HrCandidate::where('id', $validated['candidate_id'])
            ->where('tenant_id', $request->user()->tenant_id)
            ->update(['stage' => 'Offer']);

        return response()->json($offer->load('candidate'), 201);
    }

    public function show(HrOffer $offer)
    {
        return response()->json($offer->load('candidate'));
    }

    public function send(HrOffer $offer)
    {
        $offer->update(['status' => 'Sent', 'sent_at' => now()]);
        
        // Send offer letter email
        if ($offer->candidate && $offer->candidate->email) {
            \Mail::to($offer->candidate->email)->send(
                new \App\Mail\OfferLetterMail($offer)
            );
        }
        
        return response()->json($offer);
    }

    public function updateStatus(Request $request, HrOffer $offer)
    {
        $request->validate([
            'status'           => 'required|in:Generated,Sent,Accepted,Rejected',
            'rejection_reason' => 'nullable|string|max:500',
        ]);

        $data = ['status' => $request->status];

        if ($request->status === 'Accepted') {
            $data['accepted_at'] = now();
            // Move candidate to Hired
            HrCandidate::where('id', $offer->candidate_id)->update(['stage' => 'Hired', 'final_decision' => 'Selected']);
        }

        if ($request->status === 'Rejected') {
            $data['rejection_reason'] = $request->rejection_reason;
            // Update candidate's final decision
            HrCandidate::where('id', $offer->candidate_id)->update(['final_decision' => 'Rejected']);
        }

        $offer->update($data);
        return response()->json($offer);
    }


    public function destroy(HrOffer $offer)
    {
        $offer->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
