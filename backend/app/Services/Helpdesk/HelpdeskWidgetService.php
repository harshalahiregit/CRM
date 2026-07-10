<?php

namespace App\Services\Helpdesk;

use App\Exceptions\BusinessException;
use App\Models\Helpdesk\HelpdeskWidgetSetting;
use Illuminate\Support\Str;

/**
 * Public support-widget backend. An external site embeds the widget with a
 * per-tenant public key; submissions resolve the tenant from that key (never
 * the shared tenants table) and create a ticket through the normal flow.
 */
class HelpdeskWidgetService
{
    public function __construct(private HelpdeskService $helpdesk)
    {
    }

    /** Get (or lazily create) the widget settings + embeddable key for a tenant. */
    public function settingsFor(int $tenantId): HelpdeskWidgetSetting
    {
        return HelpdeskWidgetSetting::firstOrCreate(
            ['tenant_id' => $tenantId],
            ['public_key' => 'hdw_'.Str::random(40), 'is_enabled' => true],
        );
    }

    /** Resolve the tenant behind an enabled public key (for public KB reads). */
    public function resolveTenantId(string $publicKey): int
    {
        $settings = HelpdeskWidgetSetting::where('public_key', $publicKey)
            ->where('is_enabled', true)
            ->first();

        if (! $settings) {
            throw new BusinessException('This support widget is not active.', 403);
        }

        return $settings->tenant_id;
    }

    /** Regenerate the public key (invalidates old embeds). */
    public function rotateKey(int $tenantId): HelpdeskWidgetSetting
    {
        $settings = $this->settingsFor($tenantId);
        $settings->update(['public_key' => 'hdw_'.Str::random(40)]);

        return $settings;
    }

    public function updateSettings(int $tenantId, array $data): HelpdeskWidgetSetting
    {
        $settings = $this->settingsFor($tenantId);
        $settings->update(array_intersect_key($data, array_flip(['allowed_origin', 'is_enabled'])));

        return $settings;
    }

    /**
     * Public entry point: validate the key + origin, then create the ticket.
     * Returns a minimal public-safe confirmation (no internal fields leak).
     */
    public function submit(string $publicKey, ?string $origin, array $data): array
    {
        $settings = HelpdeskWidgetSetting::where('public_key', $publicKey)
            ->where('is_enabled', true)
            ->first();

        if (! $settings) {
            throw new BusinessException('This support widget is not active.', 403);
        }

        // Optional origin allowlist (exact host match when configured).
        if ($settings->allowed_origin && $origin && ! $this->originAllowed($settings->allowed_origin, $origin)) {
            throw new BusinessException('This widget is not permitted on this site.', 403);
        }

        $ticket = $this->helpdesk->createTicket([
            'subject'         => $data['subject'],
            'description'     => $data['message'],
            'priority'        => $data['priority'] ?? 'medium',
            'source'          => 'widget',
            'requester_name'  => $data['name'],
            'requester_email' => $data['email'],
        ], $settings->tenant_id);

        return [
            'reference' => 'HD-'.$ticket->id,
            'message'   => 'Thanks! Your request has been received. Our team will get back to you shortly.',
        ];
    }

    private function originAllowed(string $allowed, string $origin): bool
    {
        $host = parse_url($origin, PHP_URL_HOST) ?: $origin;
        return strcasecmp(trim($allowed), trim($host)) === 0
            || strcasecmp(trim($allowed), trim($origin)) === 0;
    }
}
