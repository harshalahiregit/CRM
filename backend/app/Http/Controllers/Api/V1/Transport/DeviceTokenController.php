<?php

namespace App\Http\Controllers\Api\V1\Transport;

use App\Domains\Integration\Services\DeviceTokenService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * STOS-INT — managing the credentials GPS units present (T-07).
 *
 * People, not hardware: this sits behind auth:sanctum with the rest of
 * /v1/fleet. The hardware door is /v1/telemetry, and the two must never be
 * confused — issuing credentials from behind the credential check would be a
 * bootstrap that lets any unit mint more.
 */
class DeviceTokenController extends Controller
{
    use ApiResponse;
    use ResolvesTransportAccess;

    public function __construct(private DeviceTokenService $tokens)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->denyExternal($request);

        return $this->success(
            $this->tokens->list(
                $this->companyId($request),
                $request->boolean('include_revoked')
            ),
            'Device tokens retrieved'
        );
    }

    /**
     * Issue a credential. The plaintext is in THIS response and nowhere else.
     */
    public function store(Request $request): JsonResponse
    {
        $this->denyExternal($request);

        $data = $request->validate([
            'device_id' => 'required|string|max:64',
            'label'     => 'nullable|string|max:120',
        ]);

        $result = $this->tokens->issue(
            $this->companyId($request),
            $data['device_id'],
            $data['label'] ?? null,
            $request->user()->id
        );

        return $this->success([
            'token'  => $result['token'],
            'plain'  => $result['plain'],
            'fitted' => $result['fitted'],
            'notice' => $result['notice'],
            'warning' => 'This is the only time this token will be shown. Copy it into the unit now.',
        ], 'Device token issued', 201);
    }

    /**
     * Rotate. The old token stays live until it is revoked, deliberately — a
     * unit in a tunnel cannot be re-flashed on our schedule.
     */
    public function rotate(Request $request, int $token): JsonResponse
    {
        $this->denyExternal($request);

        $result = $this->tokens->rotate($token, $this->companyId($request), $request->user()->id);

        return $this->success([
            'token'    => $result['token'],
            'plain'    => $result['plain'],
            'replaces' => $result['replaces'],
            'notice'   => $result['notice'],
            'warning'  => 'This is the only time this token will be shown. Copy it into the unit now.',
        ], 'Device token rotated', 201);
    }

    public function revoke(Request $request, int $token): JsonResponse
    {
        $this->denyExternal($request);

        $data = $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        return $this->success(
            $this->tokens->revoke($token, $this->companyId($request), $data['reason'] ?? null, $request->user()->id),
            'Device token revoked'
        );
    }
}
