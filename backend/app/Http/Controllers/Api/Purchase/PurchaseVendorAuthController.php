<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseVendor;
use App\Services\Purchase\PurchaseVendorPortalAuthService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Purchase Vendor Portal auth — register / verify / login / logout / password
 * reset. Authenticates ONLY a PurchaseVendor (Sanctum token, tokenable =
 * PurchaseVendor). No shared User / Vendor / TPV auth is touched.
 */
class PurchaseVendorAuthController extends Controller
{
    public function __construct(private PurchaseVendorPortalAuthService $auth)
    {
    }

    /** Public self-registration — creates only a PurchaseVendor. */
    public function register(Request $request)
    {
        $data = $request->validate([
            'tenant_id'    => ['required', 'integer', Rule::exists('tenants', 'id')],
            'company_name' => 'required|string|max:200',
            'email'        => 'required|email|max:150',
            'phone'        => 'nullable|string|max:30',
            'password'     => 'required|string|min:8|confirmed',
            // The account type chosen on the registration screen, stored as-is.
            'registration_type' => ['nullable', Rule::in(\App\Support\Purchase\PurchaseRegistrationType::ALL)],
        ]);

        $vendor = $this->auth->register($data);

        return response()->json([
            'status'  => 'success',
            'message' => 'Registration received. Please verify your email to activate your portal account.',
            'vendor'  => ['id' => $vendor->id, 'email' => $vendor->email, 'portal_status' => $vendor->portal_status],
        ], 201);
    }

    public function verifyEmail(Request $request)
    {
        $data = $request->validate(['token' => 'required|string']);
        $vendor = $this->auth->verifyEmail($data['token']);

        return response()->json(['status' => 'success', 'message' => 'Email verified. You can now sign in.', 'purchase_vendor_id' => $vendor->id]);
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $result = $this->auth->login($data['email'], $data['password'], $request->ip());

        return response()->json([
            'status'       => 'success',
            'access_token' => $result['access_token'],
            'token_type'   => $result['token_type'],
            'vendor'       => $result['vendor'],
        ], 200);
    }

    public function logout(Request $request)
    {
        $this->auth->logout($this->vendor($request));

        return response()->json(['status' => 'success', 'message' => 'Signed out.']);
    }

    public function me(Request $request)
    {
        return response()->json(['vendor' => $this->vendor($request)->loadMissing('contacts')]);
    }

    public function forgotPassword(Request $request)
    {
        $data = $request->validate(['email' => 'required|email']);
        $this->auth->forgotPassword($data['email']);

        // Never reveal whether the email exists.
        return response()->json(['status' => 'success', 'message' => 'If that email is registered, a reset link has been sent.']);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'token'    => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);
        $this->auth->resetPassword($data['email'], $data['token'], $data['password']);

        return response()->json(['status' => 'success', 'message' => 'Password updated. Please sign in.']);
    }

    /** The authenticated PurchaseVendor (Sanctum token subject). */
    private function vendor(Request $request): PurchaseVendor
    {
        $vendor = $request->user();
        // #45 — 403, not 401: an authenticated identity of the WRONG TYPE is a
        // permission failure, and a 401 here would clear the caller's session.
        // EnsurePurchaseVendorPortalAccess already answers this case with 403;
        // this is the same answer for the defence-in-depth check.
        abort_unless($vendor instanceof PurchaseVendor, 403, 'This area is for Purchase vendor accounts only.');

        return $vendor;
    }
}
