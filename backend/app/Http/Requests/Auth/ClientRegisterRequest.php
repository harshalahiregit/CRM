<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ClientRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => 'required|string|min:2',
            'last_name'  => 'required|string|min:1',
            /*
             | Unique among CONTACTS, not users.
             |
             | This was `unique:users,email`, which guarded the orphaned
             | role='client' row this endpoint used to create. It now creates a
             | ClientContact (see AuthService::registerClient), so that is where
             | the collision can happen — and it matters more here than it did
             | there: ClientPortalAuthService refuses to authenticate an address
             | that matches two portal accounts, and refuses to invite a second
             | one. Letting a duplicate in would create a contact nobody could
             | ever grant access to.
             |
             | Soft-deleted contacts are excluded on purpose: a customer removed
             | last year must not permanently block that address from signing up.
             |
             | Checking `users` is deliberately dropped. The two stores are
             | independent — one person can be both a staff login and a customer
             | contact, which is common for consultants — and the old rule turned
             | that into a registration they could not complete.
             */
            'email'      => [
                'required', 'email',
                Rule::unique('client_contacts', 'email')->whereNull('deleted_at'),
            ],
            'company'    => 'required|string|min:2',
            'phone'      => 'required|string|min:7',
            'password'   => ['required', 'confirmed', Password::min(8)],
        ];
    }

    public function messages(): array
    {
        return [
            // The default ("The email has already been taken") reads as a bug to
            // somebody who has never registered. Tell them what to do instead.
            'email.unique' => 'This email address is already on file with us. '
                .'Please ask your account manager to enable portal access, or use a different address.',
        ];
    }
}
