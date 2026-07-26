<?php

namespace App\Services\Tpv;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Notifications\NotificationService;
use App\Support\Tpv\TpvAccessStatus as Access;
use App\Support\Vendor\VendorStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Temporary TPV access lifecycle: create → (extend)* → expire | convert.
 *
 * The access window lives on the vendor (authoritative, timestamp precision);
 * `users.access_expires_at` is mirrored (day granularity) so the existing login
 * gate keeps working. Permanent vendors never enter this service.
 */
class TpvAccessService
{
    public function __construct(
        private NotificationService $notifications,
        private RegistrationNumberService $registrationNumbers,
    ) {
    }

    /** Admin/Staff creates a Temporary TPV: vendor + login + emailed credentials. */
    public function createTemporary(array $data, User $actor): array
    {
        $tid   = $actor->tenant_id;
        $start = ! empty($data['access_start_at']) ? Carbon::parse($data['access_start_at']) : now();

        $expires = ! empty($data['access_expires_at'])
            ? Carbon::parse($data['access_expires_at'])
            : $start->copy()->addDays((int) ($data['validity_days'] ?? 7));

        if ($expires->lessThanOrEqualTo($start)) {
            throw new BusinessException('Access expiry must be after the start date.');
        }

        $validity = $data['validity_days'] ?? $start->diffInDays($expires);

        return DB::transaction(function () use ($data, $tid, $start, $expires, $validity, $actor) {
            $vendor = Vendor::create([
                'tenant_id'            => $tid,
                'company_name'         => $data['company_name'],
                'email'                => $data['email'],
                'phone'                => $data['phone'] ?? null,
                'vendor_type'          => 'temporary',
                'engagements'          => ['tpv'],
                'status'               => VendorStatus::INACTIVE,
                'is_temporary'         => true,
                'access_start_at'      => $start,
                'access_expires_at'    => $expires,
                'access_status'        => Access::ACTIVE,
                'validity_days'        => $validity,
                'temporary_created_by' => $actor->id,
                'access_reminders_sent' => [],
            ]);

            $password = Str::random(12);
            $user = User::create([
                'tenant_id'         => $tid,
                'name'              => $data['company_name'],
                'email'             => $data['email'],
                'password'          => Hash::make($password),
                'role'              => 'third_party_vendor',
                'status'            => 'active',
                'tpv_type'          => 'temporary',
                'access_expires_at' => $expires->toDateString(),
                'phone'             => $data['phone'] ?? null,
            ]);
            $vendor->update(['user_id' => $user->id]);

            $this->sendCredentials($vendor, $password, $expires);
            $vendor->recordAudit('Temporary TPV Created', $actor, null, [
                'access_expires_at' => $expires->toIso8601String(), 'validity_days' => $validity,
            ]);
            Log::channel('tpv')->info('Temporary TPV created', ['vendor_id' => $vendor->id, 'tenant_id' => $tid]);

            return ['vendor' => $vendor->fresh(), 'credentials_sent' => true];
        });
    }

    /** Extend / reset the access window (with a mandatory reason). */
    public function extend(Vendor $vendor, User $actor, array $data): Vendor
    {
        $this->assertTemporary($vendor);

        $expires = ! empty($data['access_expires_at'])
            ? Carbon::parse($data['access_expires_at'])
            : now()->copy()->addDays((int) $data['validity_days']);

        if ($expires->isPast()) {
            throw new BusinessException('New expiry must be in the future.');
        }

        $vendor->update([
            'access_expires_at'  => $expires,
            'access_status'      => Access::ACTIVE,
            'access_extended_at' => now(),
            'access_extended_by' => $actor->id,
            'extension_reason'   => $data['extension_reason'],
            'validity_days'      => $data['validity_days'] ?? $vendor->validity_days,
            // New window → reminders start fresh.
            'access_reminders_sent' => [],
        ]);
        $vendor->user?->update(['access_expires_at' => $expires->toDateString(), 'status' => 'active']);

        $vendor->recordAudit('Temporary Access Extended', $actor, $data['extension_reason'], [
            'access_expires_at' => $expires->toIso8601String(),
        ]);
        $this->notify($vendor, 'Temporary access extended', "Your temporary access has been extended to {$expires->format('d M Y')}.");

        return $vendor->fresh();
    }

    /** Force-expire now: terminate sessions and lock the account. */
    public function expire(Vendor $vendor, ?User $actor = null, bool $forced = true): Vendor
    {
        $this->assertTemporary($vendor);

        if ($vendor->access_status === Access::EXPIRED) {
            return $vendor;
        }

        // Truncate the window to now so the countdown and the status agree.
        $vendor->update(['access_status' => Access::EXPIRED, 'access_expires_at' => now()]);
        if ($vendor->user) {
            $vendor->user->tokens()->delete();
            $vendor->user->update(['access_expires_at' => now()->subDay()->toDateString(), 'status' => 'inactive']);
        }

        $vendor->recordAudit('Temporary Access Expired', $actor, null, ['forced' => $forced]);
        $this->notify($vendor, 'Temporary access expired', 'Your temporary access has expired. Please contact your administrator.');

        return $vendor->fresh();
    }

    /** Lazy expiry used by the middleware/countdown when the clock has run out. */
    public function lazyExpire(Vendor $vendor): Vendor
    {
        if ($vendor->isTemporary() && $vendor->access_status !== Access::CONVERTED && $vendor->accessSecondsRemaining() <= 0) {
            return $this->expire($vendor, null, false);
        }

        return $vendor;
    }

    /** Promote to Permanent: drop expiry, issue registration number, keep history. */
    public function convert(Vendor $vendor, User $actor): Vendor
    {
        $this->assertTemporary($vendor);

        $registrationNumber = $vendor->registration_number
            ?: $this->registrationNumbers->generate($vendor->tenant_id);

        $vendor->update([
            'is_temporary'              => false,
            'vendor_type'               => 'standard',
            'access_expires_at'         => null,
            'access_status'             => Access::CONVERTED,
            'converted_to_permanent_at' => now(),
            'converted_by'              => $actor->id,
            'registration_number'       => $registrationNumber,
        ]);
        $vendor->user?->update(['access_expires_at' => null, 'status' => 'active', 'tpv_type' => 'permanent']);

        $vendor->recordAudit('Temporary TPV Converted to Permanent', $actor, null, [
            'registration_number' => $registrationNumber,
        ]);
        $this->notify($vendor, 'You are now a Permanent Vendor', "You are now a Permanent Vendor. Registration Number: {$registrationNumber}.");

        return $vendor->fresh();
    }

    /** Server-authoritative countdown projection. */
    public function project(Vendor $vendor): array
    {
        $seconds = $vendor->accessSecondsRemaining();

        return [
            'is_temporary'      => $vendor->isTemporary(),
            'access_status'     => Access::derive($vendor->access_status, $seconds),
            'access_start_at'   => optional($vendor->access_start_at)->toIso8601String(),
            'access_expires_at' => optional($vendor->access_expires_at)->toIso8601String(),
            'seconds_remaining' => $seconds,
            'band'              => Access::band($seconds),
        ];
    }

    /** Admin status view — projection, extension/convert fields, and audit timeline. */
    public function status(Vendor $vendor): array
    {
        return $this->project($vendor) + [
            'extended_at'      => optional($vendor->access_extended_at)->toIso8601String(),
            'extended_by'      => $vendor->access_extended_by,
            'extension_reason' => $vendor->extension_reason,
            'converted_at'     => optional($vendor->converted_to_permanent_at)->toIso8601String(),
            'validity_days'    => $vendor->validity_days,
            'timeline'         => $vendor->auditLogs()->latest()->take(25)
                ->get(['action', 'comment', 'metadata', 'actor_name', 'created_at']),
        ];
    }

    /** Admin listing of Temporary TPVs for a tenant, each with its countdown. */
    public function listTemporary(int $tenantId): array
    {
        return Vendor::forTenant($tenantId)
            ->where('is_temporary', true)
            ->latest()
            ->get()
            ->map(fn (Vendor $v) => array_merge([
                'id'               => $v->id,
                'company_name'     => $v->company_name,
                'vendor_code'      => $v->vendor_code,
                'email'            => $v->email,
                'phone'            => $v->phone,
                'validity_days'    => $v->validity_days,
                'extension_reason' => $v->extension_reason,
                'created_at'       => optional($v->created_at)->toIso8601String(),
            ], $this->project($v)))
            ->all();
    }

    /**
     * Scheduled sweep: dispatch due expiry reminders (7d/3d/1d/6h) once each, and
     * lazily expire any window that has run out. Runs across all tenants.
     */
    public function sendDueReminders(): array
    {
        $sent = 0;
        $expired = 0;

        Vendor::where('is_temporary', true)
            ->where('access_status', Access::ACTIVE)
            ->whereNotNull('access_expires_at')
            ->chunkById(200, function ($vendors) use (&$sent, &$expired) {
                foreach ($vendors as $vendor) {
                    $seconds = $vendor->accessSecondsRemaining();

                    if ($seconds <= 0) {
                        $this->lazyExpire($vendor);
                        $expired++;
                        continue;
                    }

                    $already = $vendor->access_reminders_sent ?? [];
                    $due = Access::dueReminders($seconds, $already);
                    if ($due === []) {
                        continue;
                    }

                    foreach ($due as $key) {
                        $this->sendReminder($vendor, $key);
                        $sent++;
                    }
                    $vendor->update(['access_reminders_sent' => array_values(array_unique([...$already, ...$due]))]);
                }
            });

        return ['reminders_sent' => $sent, 'expired' => $expired];
    }

    /* ── internals ─────────────────────────────────────────────── */

    private function sendReminder(Vendor $vendor, string $threshold): void
    {
        $labels = ['7d' => '7 days', '3d' => '3 days', '1d' => '1 day', '6h' => '6 hours'];
        $when = $labels[$threshold] ?? $threshold;

        $this->notify($vendor, 'Temporary access expiry reminder', "Your temporary access expires in {$when}.");
        $vendor->recordAudit('Temporary Expiry Reminder Sent', null, null, ['threshold' => $threshold]);
    }

    private function assertTemporary(Vendor $vendor): void
    {
        if (! $vendor->isTemporary()) {
            throw new BusinessException('This vendor is not a Temporary TPV.');
        }
    }

    private function sendCredentials(Vendor $vendor, string $password, Carbon $expires): void
    {
        $body = "Your temporary vendor portal access has been created.\n\n"
            ."Login email: {$vendor->email}\nTemporary password: {$password}\n"
            ."Access valid until: {$expires->format('d M Y')}.\n\n"
            ."Please sign in and complete your onboarding before the access expires.";

        $this->notify($vendor, 'Your Temporary Vendor Access', $body);
    }

    /** Multi-channel dispatch — email live, WhatsApp/SMS queued stubs. */
    private function notify(Vendor $vendor, string $subject, string $body): void
    {
        $ctx = ['vendor_id' => $vendor->id, 'event' => 'temporary_access'];
        $this->notifications->email($vendor->email, $subject, $body, $ctx);
        $this->notifications->whatsapp($vendor->phone, $body, $ctx);
        $this->notifications->sms($vendor->phone, $body, $ctx);
    }
}
