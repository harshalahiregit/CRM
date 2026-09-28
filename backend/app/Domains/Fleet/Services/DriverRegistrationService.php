<?php

namespace App\Domains\Fleet\Services;

use App\Domains\Fleet\Models\DriverProfile;
use App\Exceptions\BusinessException;
use App\Models\User;
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

        return DB::transaction(function () use ($reg, $tenantId, $adminUserId) {
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
                'company_id' => $tenantId, 'name' => $reg->name, 'phone' => $reg->phone,
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
