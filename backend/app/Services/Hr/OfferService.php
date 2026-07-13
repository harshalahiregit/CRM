<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrOffer;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OfferService
{
    public const DOC_DISK = 'hr_documents';

    public function __construct(
        private CandidateService $candidateService,
        private OnboardingService $onboardingService,
    ) {
    }

    public function list(int $tenantId, array $filters): Collection
    {
        $offers = HrOffer::with('candidate')
            ->whereHas('candidate', fn ($q) => $q->where('tenant_id', $tenantId));

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $offers->where('status', $filters['status']);
        } elseif (($filters['view'] ?? 'active') === 'history') {
            // Completed Offers / history — offers that are fully closed (joined).
            $offers->where('status', 'Completed');
        } else {
            // Default active view — offers still needing HR action. Completed (joined)
            // offers move to the history view.
            $offers->where('status', '!=', 'Completed');
        }

        $result = $offers->latest()->get();

        // Auto-expire any offer past its validity date on read.
        $result->each(fn ($o) => $this->expireIfDue($o));

        return $result;
    }

    public function create(array $data, int $tenantId): HrOffer
    {
        $candidate = HrCandidate::where('id', $data['candidate_id'])
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        // Enterprise gate: an offer is only generated AFTER onboarding is approved.
        $onboarding = $candidate->onboarding;
        if (! $onboarding || ! $onboarding->isApproved()) {
            throw new BusinessException('Onboarding verification must be approved before generating an offer for this candidate.', 422);
        }

        $offer = HrOffer::create([
            ...$data,
            'tenant_id'    => $tenantId,
            'status'       => 'Generated',
            'access_token' => Str::random(48),
            'generated_at' => now(),
        ]);

        HrCandidate::where('id', $candidate->id)->where('tenant_id', $tenantId)->update(['stage' => 'Offer']);

        $candidate->recordAudit('Offer Generated', null, null, array_filter(['position' => $offer->position, 'offered_ctc' => $offer->offered_ctc]));

        Log::channel('hr')->info('Offer generated', ['offer_id' => $offer->id, 'tenant_id' => $tenantId, 'candidate_id' => $candidate->id]);

        return $offer->load('candidate');
    }

    /** Send the offer: Email + WhatsApp + notification with the secure portal link. */
    public function send(HrOffer $offer): HrOffer
    {
        if (empty($offer->access_token)) {
            $offer->update(['access_token' => Str::random(48)]);
        }

        $offer->update(['status' => 'Sent', 'sent_at' => now()]);

        $link = $this->portalLink($offer);
        $candidate = $offer->candidate;

        if ($candidate && $candidate->email) {
            try {
                Mail::to($candidate->email)->send(new \App\Mail\OfferLetterMail($offer, $link));
            } catch (\Throwable $e) {
                Log::channel('hr')->error('Offer email failed', ['offer_id' => $offer->id, 'error' => $e->getMessage()]);
            }
        }

        $this->whatsApp($offer, "🎉 Congratulations! Your offer letter is ready. View & respond here: {$link}");

        $candidate?->recordAudit('Offer Sent', null, null, array_filter(['position' => $offer->position]));

        Log::channel('hr')->info('Offer sent', ['offer_id' => $offer->id, 'tenant_id' => $offer->tenant_id]);

        return $offer;
    }

    /* ─────────────── Candidate offer portal (public, token-scoped) ─────────── */

    public function byToken(string $token): HrOffer
    {
        $offer = HrOffer::where('access_token', $token)->with('candidate')->first();
        if (! $offer) {
            throw new BusinessException('Offer link is invalid or has expired.', 404);
        }

        return $offer;
    }

    /** Portal-safe offer payload (auto-expires + auto-marks Viewed). */
    public function portalView(HrOffer $offer, bool $markViewed = false): array
    {
        $this->expireIfDue($offer);

        if ($markViewed) {
            $this->markViewed($offer);
        }

        $c = $offer->candidate;

        return [
            'reference'        => 'OFF-'.str_pad((string) $offer->id, 4, '0', STR_PAD_LEFT),
            'candidate_name'   => optional($c)->name,
            'position'         => $offer->position,
            'department'       => $offer->department,
            'offered_ctc'      => $offer->offered_ctc,
            'joining_date'     => optional($offer->joining_date)->toDateString(),
            'probation_period' => $offer->probation_period,
            'notice_period'    => $offer->notice_period,
            'valid_until'      => optional($offer->validity_date)->toDateString(),
            'status'           => $offer->status,
            'is_expired'       => $offer->status === 'Expired',
            'can_respond'      => in_array($offer->status, ['Sent', 'Viewed'], true),
            'can_download'     => true,
            'accepted_at'      => optional($offer->accepted_at)->toIso8601String(),
            'accepted_name'    => $offer->accepted_name,
            'accepted_signature' => $offer->accepted_signature,
            'accepted_ip'      => $offer->accepted_ip,
            'accepted_device'  => $offer->accepted_device,
            'accepted_browser' => $offer->accepted_browser,
            'declined_at'      => optional($offer->declined_at)->toIso8601String(),
            'clarification'    => $offer->clarification,
            'pre_joining'      => $offer->status === 'Accepted' ? $this->preJoiningView($offer) : null,
        ];
    }

    /** Mark the offer Viewed the first time the candidate opens the portal. */
    public function markViewed(HrOffer $offer): void
    {
        if ($offer->status === 'Sent') {
            $offer->update(['status' => 'Viewed', 'viewed_at' => now()]);
            optional($offer->candidate)->recordAudit('Offer Viewed');
            Log::channel('hr')->info('Offer viewed by candidate', ['offer_id' => $offer->id, 'tenant_id' => $offer->tenant_id]);
        }
    }

    /**
     * Candidate accepts the offer. Records the audit fingerprint (ip/device/
     * browser). Does NOT create the Employee — that is an explicit HR "Confirm
     * Joining" action after the pre-joining checklist.
     */
    public function accept(HrOffer $offer, array $meta): HrOffer
    {
        if (! in_array($offer->status, ['Sent', 'Viewed'], true)) {
            throw new BusinessException('This offer can no longer be accepted.', 422);
        }

        $offer->update([
            'status'             => 'Accepted',
            'accepted_at'        => now(),
            'accepted_ip'        => $meta['ip'] ?? null,
            'accepted_device'    => $meta['device'] ?? null,
            'accepted_browser'   => $meta['browser'] ?? null,
            'accepted_name'      => $meta['name'] ?? null,
            'accepted_signature' => $meta['signature'] ?? null,
            'pre_joining'        => $this->initPreJoining(),
        ]);

        optional($offer->candidate)->recordAudit('Offer Accepted', null, null, array_filter([
            'signed_by' => $meta['name'] ?? null,
            'ip' => $meta['ip'] ?? null, 'device' => $meta['device'] ?? null, 'browser' => $meta['browser'] ?? null,
        ]));

        Log::channel('hr')->info('Offer accepted', ['offer_id' => $offer->id, 'tenant_id' => $offer->tenant_id, 'ip' => $meta['ip'] ?? null]);

        return $offer->fresh('candidate');
    }

    public function decline(HrOffer $offer, ?string $reason): HrOffer
    {
        if (! in_array($offer->status, ['Sent', 'Viewed'], true)) {
            throw new BusinessException('This offer can no longer be updated.', 422);
        }

        $offer->update(['status' => 'Declined', 'declined_at' => now(), 'rejection_reason' => $reason]);

        if ($offer->candidate) {
            $this->candidateService->updateDecision($offer->candidate, 'Rejected');
            $offer->candidate->recordAudit('Offer Declined', null, $reason);
        }

        Log::channel('hr')->info('Offer declined', ['offer_id' => $offer->id, 'tenant_id' => $offer->tenant_id]);

        return $offer->fresh('candidate');
    }

    public function requestClarification(HrOffer $offer, string $message): HrOffer
    {
        $offer->update(['clarification' => $message, 'clarification_at' => now()]);
        optional($offer->candidate)->recordAudit('Offer Clarification Requested', null, $message);

        Log::channel('hr')->info('Offer clarification requested', ['offer_id' => $offer->id, 'tenant_id' => $offer->tenant_id]);

        return $offer;
    }

    /** Auto-expire an offer past its validity window. */
    public function expireIfDue(HrOffer $offer): void
    {
        if ($offer->isPastValidity()) {
            $offer->update(['status' => 'Expired', 'expired_at' => now()]);
            optional($offer->candidate)->recordAudit('Offer Expired');
            Log::channel('hr')->info('Offer auto-expired', ['offer_id' => $offer->id, 'tenant_id' => $offer->tenant_id]);
        }
    }

    /** HR regenerates an expired/declined offer with a fresh validity + token. */
    public function regenerate(HrOffer $offer, ?string $validityDate): HrOffer
    {
        $offer->update([
            'status'        => 'Generated',
            'access_token'  => Str::random(48),
            'validity_date' => $validityDate ?: optional($offer->validity_date)->addDays(7) ?? now()->addDays(7),
            'generated_at'  => now(),
            'sent_at'       => null, 'viewed_at' => null, 'accepted_at' => null,
            'declined_at'   => null, 'expired_at' => null,
            'accepted_ip'   => null, 'accepted_device' => null, 'accepted_browser' => null,
        ]);

        optional($offer->candidate)->recordAudit('Offer Regenerated');
        Log::channel('hr')->info('Offer regenerated', ['offer_id' => $offer->id, 'tenant_id' => $offer->tenant_id]);

        return $offer->fresh('candidate');
    }

    /* ─────────────── Pre-joining checklist ─────────────── */

    private function initPreJoining(): array
    {
        return array_map(fn ($t) => $t + ['done' => false, 'value' => null, 'path' => null], HrOffer::PRE_JOINING_TEMPLATE);
    }

    private function preJoiningView(HrOffer $offer): array
    {
        $items = $offer->pre_joining ?: $this->initPreJoining();
        $done  = count(array_filter($items, fn ($i) => ! empty($i['done'])));

        return [
            'items'     => array_map(fn ($i) => ['key' => $i['key'], 'label' => $i['label'], 'type' => $i['type'], 'done' => (bool) ($i['done'] ?? false), 'value' => $i['value'] ?? null], $items),
            'completed' => $done,
            'total'     => count($items),
            'percent'   => (int) round($done / max(count($items), 1) * 100),
        ];
    }

    public function updatePreJoiningTask(HrOffer $offer, string $key, ?string $value, ?UploadedFile $file): HrOffer
    {
        if ($offer->status !== 'Accepted') {
            throw new BusinessException('Pre-joining tasks are available after you accept the offer.', 422);
        }

        $items = $offer->pre_joining ?: $this->initPreJoining();
        $found = false;

        foreach ($items as &$item) {
            if ($item['key'] === $key) {
                if ($item['type'] === 'file' && $file) {
                    $dir = 'onboarding/tenant_'.$offer->tenant_id.'/offer_'.$offer->id;
                    $item['path'] = $file->storeAs($dir, Str::slug($key).'_'.time().'.'.strtolower($file->getClientOriginalExtension()), self::DOC_DISK);
                    $item['done'] = true;
                } elseif ($item['type'] === 'data') {
                    $item['value'] = $value;
                    $item['done']  = ! empty($value);
                } elseif ($item['type'] === 'ack') {
                    $item['done'] = true;
                }
                $found = true;
                break;
            }
        }
        unset($item);

        if (! $found) {
            throw new BusinessException('Unknown pre-joining task.', 422);
        }

        $offer->update(['pre_joining' => $items]);

        return $offer->fresh('candidate');
    }

    /* ─────────────── Joining day (HR) ─────────────── */

    /**
     * HR confirms joining on the joining day → reuses OnboardingService to create
     * the Employee and move the candidate to Hired. Employee creation lives here
     * only (decoupled from offer acceptance).
     */
    public function confirmJoining(HrOffer $offer): HrOffer
    {
        if ($offer->status !== 'Accepted') {
            throw new BusinessException('Only an accepted offer can be confirmed for joining.', 422);
        }

        $candidate  = $offer->candidate;
        $onboarding = $candidate?->onboarding;

        if ($onboarding) {
            $this->onboardingService->confirmJoining($onboarding);
        } elseif ($candidate) {
            $this->candidateService->updateDecision($candidate, 'Selected'); // legacy → Hired
        }

        // Joining confirmed + employee created → the offer is fully closed. Mark it
        // Completed so it leaves the active Offer Letters list and moves to history.
        $offer->update(['status' => 'Completed', 'joining_confirmed_at' => now()]);

        optional($candidate)->recordAudit('Offer Completed — Joined', null, null, ['offer_id' => $offer->id]);

        Log::channel('hr')->info('Offer joining confirmed', ['offer_id' => $offer->id, 'tenant_id' => $offer->tenant_id]);

        return $offer->fresh('candidate');
    }

    /** HR pre-joining dashboard buckets by days-to-joining. */
    public function joiningBuckets(int $tenantId): array
    {
        $accepted = HrOffer::where('status', 'Accepted')->whereNull('joining_confirmed_at')
            ->whereHas('candidate', fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereNotNull('joining_date')->get();

        $today = Carbon::today();
        $buckets = ['in_15_days' => 0, 'in_7_days' => 0, 'tomorrow' => 0, 'today' => 0, 'late' => 0];

        foreach ($accepted as $o) {
            $days = $today->diffInDays($o->joining_date, false);
            if ($days < 0)       { $buckets['late']++; }
            elseif ($days === 0) { $buckets['today']++; }
            elseif ($days === 1) { $buckets['tomorrow']++; }
            elseif ($days <= 7)  { $buckets['in_7_days']++; }
            elseif ($days <= 15) { $buckets['in_15_days']++; }
        }

        return $buckets;
    }

    /* ─────────────── HR-side status override (kept for compatibility) ─────── */

    public function updateStatus(HrOffer $offer, string $status, ?string $rejectionReason): HrOffer
    {
        if ($status === 'Accepted') {
            return $this->accept($offer, []);
        }
        if (in_array($status, ['Rejected', 'Declined'], true)) {
            return $this->decline($offer, $rejectionReason);
        }

        $offer->update(['status' => $status]);

        return $offer;
    }

    public function destroy(HrOffer $offer): void
    {
        $offer->delete();

        Log::channel('hr')->info('Offer deleted', ['offer_id' => $offer->id, 'tenant_id' => $offer->tenant_id]);
    }

    /* ─────────────── helpers ─────────────── */

    public function offerLetterFile(HrOffer $offer): ?array
    {
        if (empty($offer->letter_path) || ! Storage::disk(self::DOC_DISK)->exists($offer->letter_path)) {
            return null;
        }

        return [
            'path'     => Storage::disk(self::DOC_DISK)->path($offer->letter_path),
            'filename' => 'Offer-Letter-'.$offer->id.'.'.pathinfo($offer->letter_path, PATHINFO_EXTENSION),
        ];
    }

    private function portalLink(HrOffer $offer): string
    {
        return rtrim(config('hr_publishing.offer_portal_url'), '/').'/'.$offer->access_token;
    }

    private function whatsApp(HrOffer $offer, string $message): void
    {
        $candidate = $offer->candidate;
        if (! $candidate || ! $candidate->canReceiveWhatsApp()) {
            return;
        }
        try {
            (new WhatsAppService())->send($candidate->getWhatsAppNumber(), $message, 'offer_ready', $candidate->id, $candidate->tenant_id);
        } catch (\Throwable $e) {
            Log::channel('hr')->error('Offer WhatsApp failed', ['offer_id' => $offer->id, 'error' => $e->getMessage()]);
        }
    }
}
