<?php

namespace App\Domains\Integration\Services;

use App\Domains\Fleet\Models\Vehicle;
use App\Domains\Integration\Models\TelemetryDeviceToken;
use App\Exceptions\BusinessException;
use Illuminate\Support\Facades\Log;

/**
 * STOS-INT — issuing, rotating and revoking device credentials (T-07).
 *
 * Rotation is the operation this exists for. A GPS box gets stolen with the
 * truck, or a fitter leaves, or a token ends up in a support ticket — and until
 * now the only answer was to change one fleet-wide secret and re-flash every
 * unit in every company. Here, revoking one box costs one box.
 */
class DeviceTokenService
{
    /**
     * Issue a credential for a device and return the plaintext ONCE.
     *
     * The caller is responsible for showing it; nothing stores it. That is the
     * same bargain every API key makes, and the alternative — a token readable
     * from the database forever — means a database backup is a fleet takeover.
     */
    public function issue(int $companyId, string $deviceId, ?string $label, ?int $userId = null): array
    {
        $deviceId = trim($deviceId);

        if ($deviceId === '') {
            throw new BusinessException('A token has to name the device it belongs to.');
        }

        // Not a hard requirement — a fitter may credential a box before it is
        // bolted to anything — but an unknown device id is far more often a
        // typo, and a typo here produces a unit that silently never reports.
        $known = Vehicle::forCompany($companyId)->where('gps_device_id', $deviceId)->exists();

        $plain = TelemetryDeviceToken::generate();

        $token = TelemetryDeviceToken::create([
            'company_id' => $companyId,
            'device_id'  => $deviceId,
            'label'      => $label ?: null,
            'token_hash' => TelemetryDeviceToken::hash($plain),
            'created_by' => $userId,
        ]);

        Log::channel('stos')->info('Device token issued', [
            'company_id' => $companyId, 'device_id' => $deviceId,
            'token_id' => $token->id, 'user_id' => $userId,
        ]);

        return [
            'token'  => $token->fresh(),
            // The only time this value exists outside the device.
            'plain'  => $plain,
            'fitted' => $known,
            'notice' => $known
                ? null
                : 'No vehicle in this fleet is registered to device '.$deviceId.' yet. The token will work, but nothing will resolve its pings until a vehicle claims that device id.',
        ];
    }

    /**
     * Replace a device's credential without an outage.
     *
     * The new token is issued and the old one stays live until it is explicitly
     * revoked, because a unit in a tunnel cannot be re-flashed on our schedule.
     * Revoking first would mean every rotation is a gap in the trail.
     */
    public function rotate(int $tokenId, int $companyId, ?int $userId = null): array
    {
        $existing = $this->find($tokenId, $companyId);

        $issued = $this->issue($companyId, $existing->device_id, $existing->label, $userId);

        Log::channel('stos')->info('Device token rotated', [
            'company_id' => $companyId, 'device_id' => $existing->device_id,
            'old_token_id' => $existing->id, 'new_token_id' => $issued['token']->id,
        ]);

        return [
            ...$issued,
            'replaces' => $existing->id,
            'notice'   => 'The previous token is still live. Revoke it once the unit is reporting on the new one.',
        ];
    }

    public function revoke(int $tokenId, int $companyId, ?string $reason = null, ?int $userId = null): TelemetryDeviceToken
    {
        $token = $this->find($tokenId, $companyId);

        if (! $token->isActive()) {
            throw new BusinessException('That token is already revoked.');
        }

        $token->update([
            'revoked_at'     => now(),
            'revoked_reason' => $reason,
        ]);

        Log::channel('stos')->warning('Device token revoked', [
            'company_id' => $companyId, 'device_id' => $token->device_id,
            'token_id' => $token->id, 'reason' => $reason, 'user_id' => $userId,
        ]);

        return $token->fresh();
    }

    /**
     * Resolve a presented token to its company and device.
     *
     * Returns null for anything that does not verify — an unknown token, a
     * revoked one. The caller turns that into a 401; this does not distinguish
     * between them out loud, because telling a caller "that token exists but is
     * revoked" confirms a valid guess.
     */
    public function verify(string $presented): ?TelemetryDeviceToken
    {
        $presented = trim($presented);

        if ($presented === '') {
            return null;
        }

        // Looked up BY HASH, so the comparison is an indexed equality check on
        // a fixed-length digest rather than a scan — there is no timing signal
        // to leak and no secret in the query.
        $token = TelemetryDeviceToken::where('token_hash', TelemetryDeviceToken::hash($presented))->first();

        if (! $token || ! $token->isActive()) {
            return null;
        }

        // Written without touching `updated_at`: this fires on every ping from
        // every unit, and it is a usage stamp, not an edit of the credential.
        TelemetryDeviceToken::where('id', $token->id)->update(['last_used_at' => now()]);

        return $token;
    }

    /** The credentials a fleet holds, newest first. Never the hashes. */
    public function list(int $companyId, bool $includeRevoked = false): array
    {
        $tokens = TelemetryDeviceToken::forCompany($companyId)
            ->when(! $includeRevoked, fn ($q) => $q->whereNull('revoked_at'))
            ->orderByDesc('id')->get();

        $fitted = Vehicle::forCompany($companyId)
            ->whereNotNull('gps_device_id')
            ->pluck('registration_number', 'gps_device_id');

        return [
            'tokens' => $tokens->map(fn (TelemetryDeviceToken $t) => [
                ...$t->toArray(),
                // Which truck this box is currently bolted to, if any.
                'vehicle' => $fitted[$t->device_id] ?? null,
                'active'  => $t->isActive(),
            ])->all(),

            // Devices that report but have no credential of their own are the
            // ones still relying on the shared secret — the list of what is
            // left to migrate.
            'uncredentialed_devices' => $fitted->keys()
                ->reject(fn ($d) => $tokens->where('device_id', $d)->where('revoked_at', null)->isNotEmpty())
                ->values()->all(),
        ];
    }

    private function find(int $tokenId, int $companyId): TelemetryDeviceToken
    {
        $token = TelemetryDeviceToken::forCompany($companyId)->find($tokenId);

        if (! $token) {
            throw new BusinessException('That device token does not exist.', 404);
        }

        return $token;
    }
}
