<?php

namespace App\Services\WhatsApp;

use App\Models\TenantWhatsAppSetting;

/**
 * Which number a tenant's WhatsApp leaves from.
 *
 * Same rule as TenantMailer, for the same reason: a message about THIS
 * company's leave request must arrive from this company's number, and changing
 * that cannot mean editing .env and redeploying. A tenant that has set nothing
 * up falls back to the platform sender in .env.
 *
 * Returns null when neither is configured. Callers log and move on -- a missing
 * WhatsApp sender must never take down the thing that triggered the message.
 */
class TenantWhatsApp
{
    public function settingsFor(int $tenantId): ?TenantWhatsAppSetting
    {
        $s = TenantWhatsAppSetting::where('tenant_id', $tenantId)->first();

        return ($s && $s->isUsable()) ? $s : null;
    }

    public function clientFor(int $tenantId): ?CloudApiClient
    {
        $s = $this->settingsFor($tenantId);

        if ($s) {
            return new CloudApiClient($s->access_token, $s->phone_number_id, $s->api_version ?: 'v21.0');
        }

        return $this->platformClient();
    }

    /** The .env sender. Also what the Test button uses before anything is saved. */
    public function platformClient(): ?CloudApiClient
    {
        $token = (string) config('whatsapp.cloud.token');
        $phoneId = (string) config('whatsapp.cloud.phone_number_id');

        if ($token === '' || $phoneId === '') {
            return null;
        }

        return new CloudApiClient($token, $phoneId, (string) config('whatsapp.cloud.api_version', 'v21.0'));
    }

    /** Whether anything at all can send for this tenant. */
    public function isConfigured(int $tenantId): bool
    {
        return $this->clientFor($tenantId) !== null;
    }

    /**
     * Where a tenant's WhatsApp is coming from, for the settings screen.
     * "It works, but from the platform number" is a different thing to explain
     * than "it works", and an admin who cannot tell them apart will not
     * understand why their customers see an unfamiliar sender.
     */
    public function sourceFor(int $tenantId): string
    {
        if ($this->settingsFor($tenantId)) {
            return 'tenant';
        }

        return $this->platformClient() ? 'platform' : 'none';
    }
}
