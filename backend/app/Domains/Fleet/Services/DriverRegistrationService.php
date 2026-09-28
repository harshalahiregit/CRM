<?php

namespace App\Domains\Fleet\Services;

use App\Domains\Fleet\Models\DriverProfile;
use App\Exceptions\BusinessException;
use App\Models\User;
use App\Services\Mail\TenantMailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * STOS-FLEET — a driver asks to join; the office decides (register → approve).
 *
 * Signing up in the app files a PENDING request, never a live account. An admin
 * approves it on the drivers board, and only then is a login and a driver
 * profile created. This is the whole point: a fleet does not let anyone with the
 * APK grant themselves a driver account.
 */
class DriverRegistrationService
{
    public function __construct(private TenantMailer $mailer)
    {
    }

    /**
     * File a driver's request to join. Returns nothing usable to log in with —
     * that is the design.
     */
    public function register(int $tenantId, array $data): array
    {
        $email = strtolower(trim($data['email']));

        // Already a real account? Then this is a returning driver, not a signup.
        if (User::where('email', $email)->exists()) {
            throw new BusinessException('An account with that email already exists. Try signing in instead.');
        }

        // A second request from the same person updates the first rather than
        // stacking the queue.
        $existing = DB::table('driver_registrations')
            ->where('tenant_id', $tenantId)->where('email', $email)
            ->where('status', 'pending')->first();

        $row = [
            'tenant_id'      => $tenantId,
            'name'           => trim($data['name']),
            'phone'          => isset($data['phone']) ? trim((string) $data['phone']) ?: null : null,
            'email'          => $email,
            'password'       => Hash::make($data['password']),
            'licence_number' => isset($data['licence_number']) ? trim((string) $data['licence_number']) ?: null : null,
            'licence_class'  => $data['licence_class'] ?? null,
            'status'         => 'pending',
            'updated_at'     => now(),
        ];

        if ($existing) {
            DB::table('driver_registrations')->where('id', $existing->id)->update($row);
        } else {
            $row['created_at'] = now();
            DB::table('driver_registrations')->insert($row);
        }

        Log::channel('stos')->info('Driver registration filed', ['tenant_id' => $tenantId, 'email' => $email]);

        return ['status' => 'pending', 'message' => 'Your request has been sent. An admin will approve it, then you can sign in.'];
    }

    /** The queue the admin reviews. */
    public function pending(int $tenantId): array
    {
        return DB::table('driver_registrations')
            ->where('tenant_id', $tenantId)->where('status', 'pending')
            ->orderBy('created_at')
            ->get(['id', 'name', 'phone', 'email', 'licence_number', 'licence_class', 'created_at'])
            ->map(fn ($r) => (array) $r)->all();
    }

    /**
     * Approve: create the login and the driver profile, in one transaction, and
     * mark the request done. From here the driver can sign in with the password
     * they chose.
     */
    public function approve(int $tenantId, int $id, int $adminUserId): array
    {
        $reg = DB::table('driver_registrations')
            ->where('tenant_id', $tenantId)->where('id', $id)->where('status', 'pending')->first();

        if (! $reg) {
            throw new BusinessException('That registration is not waiting for approval.', 404);
        }

        if (User::where('email', $reg->email)->exists()) {
            throw new BusinessException('An account with that email already exists.');
        }

        $result = DB::transaction(function () use ($reg, $tenantId, $adminUserId) {
            // The login. `password` is already hashed on the request, so it is
            // moved across as-is — the driver's chosen password just works.
            $user = new User();
            $user->forceFill([
                'tenant_id' => $tenantId,
                'name'      => $reg->name,
                'email'     => $reg->email,
                'password'  => $reg->password,
                'role'      => 'staff',
                'status'    => 'active',
                'phone'     => $reg->phone,
            ])->save();

            // The person in STOS's own driver register, plus the licence overlay,
            // so the approved driver appears on the board like any other.
            $personId = DB::table('stos_drivers')->insertGetId([
                'company_id' => $tenantId, 'user_id' => $user->id, 'name' => $reg->name, 'phone' => $reg->phone,
                'designation' => 'Driver', 'created_at' => now(), 'updated_at' => now(),
            ]);

            DriverProfile::create([
                'company_id' => $tenantId, 'source' => 'stos', 'source_id' => $personId,
                'licence_number' => $reg->licence_number, 'licence_class' => $reg->licence_class,
                'status' => DriverProfile::AVAILABLE,
            ]);

            DB::table('driver_registrations')->where('id', $reg->id)->update([
                'status' => 'approved', 'reviewed_by' => $adminUserId, 'reviewed_at' => now(),
                'created_user_id' => $user->id, 'updated_at' => now(),
            ]);

            Log::channel('stos')->info('Driver registration approved', [
                'tenant_id' => $tenantId, 'registration_id' => $reg->id, 'user_id' => $user->id, 'by' => $adminUserId,
            ]);

            return ['id' => $reg->id, 'user_id' => $user->id, 'name' => $reg->name, 'email' => $reg->email];
        });

        // Tell the driver they are in — AFTER the account exists, so we never
        // email a welcome for a login that a rollback threw away. Best-effort:
        // the approval already succeeded, so a mail problem (no SMTP set up, a
        // slow host) must not undo it. We record whether it went.
        $result['emailed'] = $this->sendApprovalEmail($tenantId, $reg->name, $reg->email);

        return $result;
    }

    /**
     * The welcome email. It confirms the account is live and tells the driver to
     * sign in with the password THEY chose at registration — we never store or
     * send the plaintext password, so there is nothing secret to leak here.
     */
    private function sendApprovalEmail(int $tenantId, string $name, string $email): bool
    {
        $first = trim(explode(' ', trim($name))[0] ?: 'there');
        $subject = 'Your Sangoé Driver account is approved';

        $html = <<<HTML
            <div style="font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:0 auto;color:#1f2937;">
              <div style="background:#0a0f1c;padding:24px 28px;border-radius:14px 14px 0 0;">
                <span style="color:#eef3fb;font-size:20px;font-weight:800;">🚚 Sangoé Driver</span>
              </div>
              <div style="border:1px solid #e5e7eb;border-top:0;border-radius:0 0 14px 14px;padding:28px;">
                <p style="font-size:16px;margin:0 0 14px;">Hi {$first},</p>
                <p style="font-size:15px;line-height:22px;margin:0 0 16px;">
                  Good news — the office has <strong>approved your driver account</strong>.
                  You can now sign in to the Sangoé Driver app.
                </p>
                <table style="background:#f3f4f6;border-radius:10px;padding:16px;width:100%;margin:0 0 16px;">
                  <tr><td style="font-size:13px;color:#6b7280;padding:2px 0;">Email</td></tr>
                  <tr><td style="font-size:15px;font-weight:700;padding:0 0 8px;">{$email}</td></tr>
                  <tr><td style="font-size:13px;color:#6b7280;padding:2px 0;">Password</td></tr>
                  <tr><td style="font-size:15px;font-weight:700;">The password you chose when you registered</td></tr>
                </table>
                <p style="font-size:14px;line-height:21px;color:#4b5563;margin:0 0 6px;">
                  Open the Sangoé Driver app, enter the email and password above, and you're in.
                  If you forgot your password, ask the office to reset it.
                </p>
              </div>
              <p style="font-size:12px;color:#9ca3af;text-align:center;margin:16px 0 0;">
                You received this because your driver registration was approved.
              </p>
            </div>
            HTML;

        $text = "Hi {$first},\n\nYour Sangoé Driver account is approved. "
            . "Sign in to the app with:\n  Email: {$email}\n  Password: the one you chose at registration\n\n"
            . "If you forgot it, ask the office to reset it.";

        try {
            $this->mailer->sendRawHtml($tenantId, $email, $subject, $html, $text);
            Log::channel('stos')->info('Driver approval email sent', ['tenant_id' => $tenantId, 'email' => $email]);

            return true;
        } catch (\Throwable $e) {
            Log::channel('stos')->warning('Driver approval email not sent', [
                'tenant_id' => $tenantId, 'email' => $email, 'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** Reject with a reason. No account is created. */
    public function reject(int $tenantId, int $id, int $adminUserId, ?string $reason): array
    {
        $reg = DB::table('driver_registrations')
            ->where('tenant_id', $tenantId)->where('id', $id)->where('status', 'pending')->first();

        if (! $reg) {
            throw new BusinessException('That registration is not waiting for approval.', 404);
        }

        DB::table('driver_registrations')->where('id', $reg->id)->update([
            'status' => 'rejected', 'reject_reason' => $reason ? trim($reason) : null,
            'reviewed_by' => $adminUserId, 'reviewed_at' => now(), 'updated_at' => now(),
        ]);

        return ['id' => $reg->id, 'status' => 'rejected'];
    }
}
