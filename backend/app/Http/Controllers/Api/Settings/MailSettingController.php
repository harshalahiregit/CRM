<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateMailSettingRequest;
use App\Models\TenantMailSetting;
use App\Services\Mail\TenantMailer;
use Illuminate\Http\Request;

class MailSettingController extends Controller
{
    public function __construct(private TenantMailer $tenantMailer)
    {
    }

    /* ── Current tenant's mail settings ────────────────────────── */
    public function show(Request $request)
    {
        $settings = TenantMailSetting::forTenant($request->user()->tenant_id)->first();

        return response()->json($settings ?? [
            'host' => null, 'port' => 587, 'username' => null,
            'encryption' => 'tls', 'from_name' => null, 'from_email' => null,
            'reply_to' => null, 'enabled' => false, 'has_password' => false,
        ]);
    }

    /* ── Save (upsert) ─────────────────────────────────────────── */
    public function update(UpdateMailSettingRequest $request)
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validated();

        // Blank password means "keep existing" — never overwrite with empty.
        if (! array_key_exists('password', $data) || $data['password'] === null || $data['password'] === '') {
            unset($data['password']);
        }

        $settings = TenantMailSetting::forTenant($tenantId)->first()
            ?? new TenantMailSetting(['tenant_id' => $tenantId]);
        $settings->fill($data);
        $settings->tenant_id = $tenantId;
        $settings->save();

        return response()->json($settings->fresh());
    }

    /* ── Send a test email through the saved config ────────────── */
    public function testSend(Request $request)
    {
        $data = $request->validate(['to' => 'required|email']);

        // Uses saved settings (or the .env fallback when none are enabled) —
        // exactly the path real sends will take.
        $this->tenantMailer->send($request->user()->tenant_id, $data['to'], new \App\Mail\Settings\TestMail());

        return response()->json(['message' => 'Test email sent to ' . $data['to']]);
    }

    /* ── Staff master email switches ───────────────────────────── */
    // The meeting's "one global disable-all-emails" control, staff side.
    // (Client contacts get theirs in the contact form.)
    public function staffEmails(Request $request)
    {
        return response()->json(
            \App\Models\User::where('tenant_id', $request->user()->tenant_id)
                ->where('role', '!=', 'client')
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'role', 'emails_enabled'])
        );
    }

    public function toggleStaffEmails(Request $request, \App\Models\User $user)
    {
        abort_if($user->tenant_id !== $request->user()->tenant_id, 404);
        $user->update(['emails_enabled' => ! $user->emails_enabled]);

        return response()->json(['id' => $user->id, 'emails_enabled' => (bool) $user->emails_enabled]);
    }
}
