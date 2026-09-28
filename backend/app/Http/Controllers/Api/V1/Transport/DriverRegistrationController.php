<?php

namespace App\Http\Controllers\Api\V1\Transport;

use App\Domains\Fleet\Services\DriverRegistrationService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * STOS-FLEET — driver self-registration, gated by admin approval.
 *
 * `register` is PUBLIC (a driver has no login yet). The rest are admin actions
 * behind role:admin,staff.
 */
class DriverRegistrationController extends Controller
{
    use ApiResponse;
    use ResolvesTransportAccess;

    // The company a public sign-up lands in. Single-company for now; a real
    // multi-company deployment would carry a company code on the request.
    private const DEFAULT_TENANT = 1;

    public function __construct(private DriverRegistrationService $registrations)
    {
    }

    /** PUBLIC — a driver asks to join. Files a pending request, no login yet. */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'           => 'required|string|max:150',
            'email'          => 'required|email|max:190',
            'password'       => 'required|string|min:6|max:100',
            'phone'          => 'nullable|string|max:30',
            'licence_number' => 'nullable|string|max:40',
            'licence_class'  => ['nullable', 'string', 'max:20'],
        ]);

        return $this->success(
            $this->registrations->register(self::DEFAULT_TENANT, $data),
            'Registration received', 201
        );
    }

    /** ADMIN — the queue of drivers waiting to be let in. */
    public function pending(Request $request): JsonResponse
    {
        $this->denyExternal($request);

        return $this->success(
            ['registrations' => $this->registrations->pending($this->companyId($request))],
            'Pending driver registrations'
        );
    }

    /** ADMIN — approve: creates the login and the driver profile. */
    public function approve(Request $request, int $registration): JsonResponse
    {
        $this->denyExternal($request);

        $result = $this->registrations->approve($this->companyId($request), $registration, (int) $request->user()->id);

        $message = ($result['emailed'] ?? false)
            ? 'Driver approved — a sign-in email was sent to them'
            : 'Driver approved — they can now sign in (email not sent; check Settings → Email)';

        return $this->success($result, $message);
    }

    /** ADMIN — reject with a reason. No account is created. */
    public function reject(Request $request, int $registration): JsonResponse
    {
        $this->denyExternal($request);

        $data = $request->validate(['reason' => 'nullable|string|max:255']);

        return $this->success(
            $this->registrations->reject($this->companyId($request), $registration, (int) $request->user()->id, $data['reason'] ?? null),
            'Registration rejected'
        );
    }
}
