<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Services\Customer\ClientPortalAuthService;
use Illuminate\Http\Request;

/**
 * Customer portal auth — login, logout, set-password and reset.
 *
 * There is no self-registration. A customer contact is created by staff, in the
 * CRM, against a real customer record; letting anyone claim to be a contact of
 * an existing company would be an account-takeover route dressed as a feature.
 * Access always starts with a staff member sending an invitation.
 */
class ClientPortalAuthController extends Controller
{
    public function __construct(private ClientPortalAuthService $auth)
    {
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $result = $this->auth->login($data['email'], $data['password'], $request->ip());
        $contact = $result['contact'];

        return response()->json([
            'status' => 'success',
            'data'   => [
                'access_token' => $result['token'],
                'token_type'   => 'Bearer',
                'contact'      => [
                    'id'          => $contact->id,
                    'first_name'  => $contact->first_name,
                    'last_name'   => $contact->last_name,
                    'email'       => $contact->email,
                    'permissions' => is_array($contact->permissions) ? $contact->permissions : [],
                ],
                'company' => $contact->client?->company,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $this->auth->logout($request->user());

        return response()->json(['status' => 'success']);
    }

    public function forgotPassword(Request $request)
    {
        $data = $request->validate(['email' => 'required|email']);
        $this->auth->forgotPassword($data['email']);

        // Always the same answer, whether or not the address exists.
        return response()->json([
            'status'  => 'success',
            'message' => 'If that email has portal access, a reset link is on its way.',
        ]);
    }

    public function setPassword(Request $request)
    {
        $data = $request->validate([
            'token'    => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $this->auth->setPassword($data['token'], $data['password']);

        return response()->json([
            'status'  => 'success',
            'message' => 'Your password has been set. You can now sign in.',
        ]);
    }
}
