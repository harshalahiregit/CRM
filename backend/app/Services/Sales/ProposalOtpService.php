<?php

namespace App\Services\Sales;

use App\Exceptions\BusinessException;
use App\Mail\Sales\ProposalOtpMail;
use App\Models\Sales\Proposal;
use App\Models\Sales\ProposalOtp;
use App\Services\Mail\TenantMailer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Email-only OTP gate for public proposal links (meeting 2.4). The code is
 * never returned by the API; a successful verify issues a short-lived
 * access token the portal passes back via the X-Portal-Access header.
 */
class ProposalOtpService
{
    private const CODE_TTL_MINUTES   = 10;
    private const ACCESS_TTL_MINUTES = 30;
    private const MAX_ATTEMPTS       = 5;
    private const MAX_ACTIVE_PER_PROPOSAL = 10; // server-side cap on outstanding codes

    public function __construct(private TenantMailer $tenantMailer)
    {
    }

    /** @return array{email: string} masked recipient email */
    public function request(Proposal $proposal): array
    {
        // Zero-trust: the OTP goes to the intended RECIPIENT(s) — the client on
        // the proposal's To (recipient contact) and any CC addresses. A leaked/
        // forwarded link is useless because the code only lands in the mailbox
        // of whoever the proposal was legitimately addressed to, not whoever
        // happens to hold the URL.
        $proposal->loadMissing('contact');
        $to = $proposal->contact?->email ?: $proposal->email;
        if (! $to) {
            throw new BusinessException('No recipient email is on file for this proposal.', 422);
        }
        // CC the same people the proposal itself was sent to.
        $cc = array_values(array_filter(array_unique($proposal->email_cc ?? []), fn ($e) => $e && $e !== $to));

        $recent = ProposalOtp::where('proposal_id', $proposal->id)
            ->where('created_at', '>=', now()->subHour())->count();
        if ($recent >= self::MAX_ACTIVE_PER_PROPOSAL) {
            throw new BusinessException('Too many codes requested. Try again later.', 429);
        }

        // Any previously issued, unverified code becomes invalid.
        ProposalOtp::where('proposal_id', $proposal->id)->whereNull('consumed_at')->delete();

        $code = (string) random_int(100000, 999999);
        ProposalOtp::create([
            'tenant_id'   => $proposal->tenant_id,
            'proposal_id' => $proposal->id,
            'code_hash'   => Hash::make($code),
            'expires_at'  => now()->addMinutes(self::CODE_TTL_MINUTES),
        ]);

        $this->tenantMailer->send($proposal->tenant_id, $to, new ProposalOtpMail($proposal, $code), $cc);

        // Masked recipient email so the client sees where the code went.
        return ['email' => $this->maskEmail($to), 'recipient' => 'client'];
    }

    /** @return array{access_token: string, expires_in: int} */
    public function verify(Proposal $proposal, string $code): array
    {
        $otp = ProposalOtp::where('proposal_id', $proposal->id)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest('id')->first();

        if (! $otp) {
            throw new BusinessException('Code expired or not requested. Request a new code.', 422);
        }

        // Count the attempt BEFORE comparing, so brute force can't retry free.
        $otp->increment('attempts');
        if ($otp->attempts > self::MAX_ATTEMPTS) {
            $otp->delete();
            throw new BusinessException('Too many incorrect attempts. Request a new code.', 429);
        }

        if (! Hash::check($code, $otp->code_hash)) {
            throw new BusinessException('Incorrect code.', 422);
        }

        $accessToken = Str::random(48);
        $otp->update([
            'consumed_at'       => now(),
            'access_token_hash' => hash('sha256', $accessToken),
            'access_expires_at' => now()->addMinutes(self::ACCESS_TTL_MINUTES),
        ]);

        return ['access_token' => $accessToken, 'expires_in' => self::ACCESS_TTL_MINUTES * 60];
    }

    /** True when the header token grants access to this proposal. */
    public function hasAccess(Proposal $proposal, ?string $accessToken): bool
    {
        if (! $proposal->public_view_otp_enabled) {
            return true;
        }
        if (! $accessToken) {
            return false;
        }

        return ProposalOtp::where('proposal_id', $proposal->id)
            ->where('access_token_hash', hash('sha256', $accessToken))
            ->where('access_expires_at', '>', now())
            ->exists();
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);
        $keep = min(2, strlen($local));

        return substr($local, 0, $keep).str_repeat('•', max(strlen($local) - $keep, 1)).'@'.$domain;
    }
}
