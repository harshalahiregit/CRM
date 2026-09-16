<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use App\Models\TenantWhatsAppSetting;
use App\Services\WhatsApp\CloudApiClient;
use App\Services\WhatsApp\TenantWhatsApp;
use Illuminate\Http\Request;

/**
 * A workspace's own WhatsApp sender, set from the UI.
 *
 * Deliberately the same shape as MailSettingController: the token is write-only
 * (the screen gets has_token), and saving it blank means "keep the one you
 * have" so changing the phone number does not require pasting the token again.
 *
 * The point of this screen is that swapping an expired token is an admin
 * pasting a string, not a developer editing .env and redeploying -- which is
 * how a WhatsApp integration ends up dead for a week.
 */
class WhatsAppSettingController extends Controller
{
    public function __construct(private TenantWhatsApp $tenants)
    {
    }

    public function show(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $s = TenantWhatsAppSetting::where('tenant_id', $tenantId)->first();

        return response()->json([
            'settings' => $s ?? [
                'provider' => 'cloud', 'phone_number_id' => null, 'waba_id' => null,
                'api_version' => 'v21.0', 'display_phone_number' => null,
                'verified_name' => null, 'enabled' => false, 'has_token' => false,
            ],
            // Which number this workspace's messages actually leave from today.
            // "Working" and "working, from the platform number" look identical
            // on this screen otherwise, and customers see a different sender.
            'source'   => $this->tenants->sourceFor($tenantId),
            'template' => [
                'name'     => config('whatsapp.cloud.notification_template'),
                'language' => config('whatsapp.cloud.notification_template_language'),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $this->can($request);

        $data = $request->validate([
            'provider'        => 'nullable|string|in:cloud,twilio',
            'access_token'    => 'nullable|string|max:1000',
            'phone_number_id' => 'nullable|string|max:64',
            'waba_id'         => 'nullable|string|max:64',
            'api_version'     => 'nullable|string|max:12',
            'enabled'         => 'boolean',
        ]);

        // Blank token means keep the existing one — never overwrite with empty.
        if (($data['access_token'] ?? '') === '') {
            unset($data['access_token']);
        }

        $tenantId = (int) $request->user()->tenant_id;
        $s = TenantWhatsAppSetting::where('tenant_id', $tenantId)->first()
            ?? new TenantWhatsAppSetting(['tenant_id' => $tenantId]);

        $s->fill($data);
        $s->tenant_id = $tenantId;
        $s->save();

        return response()->json(['settings' => $s->fresh(), 'source' => $this->tenants->sourceFor($tenantId)]);
    }

    /**
     * Ask Meta who these credentials are, and store what it says.
     *
     * A read, not a send: an admin wants to know the token works before any
     * customer receives anything, and a test that costs a message is a test
     * people avoid running.
     */
    public function verify(Request $request)
    {
        $this->can($request);

        $tenantId = (int) $request->user()->tenant_id;
        $client = $this->tenants->clientFor($tenantId);

        if (! $client) {
            return response()->json(['ok' => false, 'error' => 'No WhatsApp credentials saved for this workspace.'], 422);
        }

        $result = $client->identity();

        if (! $result->ok) {
            return response()->json(['ok' => false, 'error' => $result->error], 422);
        }

        $raw = $result->raw;

        if ($s = TenantWhatsAppSetting::where('tenant_id', $tenantId)->first()) {
            $s->update([
                'display_phone_number' => $raw['display_phone_number'] ?? null,
                'verified_name'        => $raw['verified_name'] ?? null,
                'verified_at'          => now(),
            ]);
        }

        return response()->json([
            'ok'     => true,
            'sender' => [
                'verified_name'        => $raw['verified_name'] ?? null,
                'display_phone_number' => $raw['display_phone_number'] ?? null,
                'quality_rating'       => $raw['quality_rating'] ?? null,
                'status'               => $raw['status'] ?? null,
            ],
            // Worth telling an admin about, but neither blocks sending — a
            // number sends fine with both outstanding. They decide what a
            // recipient sees as the sender, which is its own problem.
            'warnings' => array_values(array_filter([
                ($raw['name_status'] ?? null) === 'DECLINED'
                    ? 'Meta declined the display name on this number, so recipients see the raw number. Submit a new name in WhatsApp Manager.' : null,
                ($raw['code_verification_status'] ?? null) === 'EXPIRED'
                    ? 'This number\'s verification has expired. Re-verify it in WhatsApp Manager to change the display name.' : null,
            ])),
        ]);
    }

    /** One real message, to a number the admin names. */
    public function testSend(Request $request)
    {
        $this->can($request);

        $data = $request->validate(['to' => 'required|string|max:20']);

        $client = $this->tenants->clientFor((int) $request->user()->tenant_id);
        if (! $client) {
            return response()->json(['ok' => false, 'error' => 'No WhatsApp credentials saved.'], 422);
        }

        // hello_world ships with every Cloud API account, so this works before
        // anything of ours has been through review.
        $result = $client->sendTemplate(CloudApiClient::normalise($data['to']), 'hello_world', 'en_US');

        if (! $result->ok) {
            return response()->json(['ok' => false, 'error' => $result->error], 422);
        }

        return response()->json([
            'ok'      => true,
            // Accepted is not delivered. Saying "sent" here is how a broken
            // integration passes its own test.
            'message' => 'Meta accepted the message (id '.$result->messageId.'). Delivery is confirmed separately.',
        ]);
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->isAdmin(), 403, 'Only an administrator may change the WhatsApp sender');
    }
}
