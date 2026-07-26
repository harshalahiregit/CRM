<?php

namespace App\Services\Helpdesk;

use App\Exceptions\BusinessException;
use App\Models\Helpdesk\HelpdeskWidgetSetting;
use App\Models\Helpdesk\TicketPriority;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Public support-widget backend. An external site embeds the widget with a
 * per-tenant public key; submissions resolve the tenant from that key (never
 * the shared tenants table) and create a ticket through the normal flow.
 */
class HelpdeskWidgetService
{
    public function __construct(
        private HelpdeskService $helpdesk,
        private TicketAssignmentService $assignment,
    ) {
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

        $data = array_intersect_key($data, array_flip(['allowed_origin', 'is_enabled', 'department_id']));

        // Store the allowlist canonically ("a.com,b.com") whatever the admin typed
        // — the controller has already validated each entry is a real hostname.
        if (array_key_exists('allowed_origin', $data)) {
            $hosts = self::parseOriginList((string) ($data['allowed_origin'] ?? ''));
            $data['allowed_origin'] = $hosts ? implode(',', $hosts) : null;
        }

        $settings->update($data);

        return $settings->fresh('department');
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

        $this->assertOriginAllowed($settings, $origin);

        $ticket = $this->helpdesk->createTicket([
            'subject'         => $data['subject'],
            'description'     => $data['message'],
            // Fall back to the tenant's *configured* default priority rather than a
            // hardcoded 'medium', which stops existing on the first rename (BUG-15).
            'priority'        => $data['priority'] ?? $this->defaultPriorityName($settings->tenant_id),
            'source'          => 'widget',
            'requester_name'  => $data['name'],
            'requester_email' => $data['email'],
            // BUG-29: the widget never sent a department, so every public ticket
            // depended on a tenant default being configured — when it wasn't, the
            // ticket landed with department_id = null and no department manager was
            // ever notified. Null here still falls back to that default inside
            // createTicket(), so nothing changes for tenants that don't set one.
            'department_id'   => $settings->department_id,
        ], $settings->tenant_id);

        // REQ-06: a widget ticket has no human raising it, so nobody picks an owner.
        // Best-effort — an auto-assign failure must never fail the submission the
        // customer just made (their ticket exists and managers are already notified).
        try {
            $this->assignment->autoAssign($ticket);
        } catch (\Throwable $e) {
            Log::warning("Widget auto-assign failed for ticket #{$ticket->id}: {$e->getMessage()}");
        }

        return [
            'reference' => 'HD-'.$ticket->id,
            'message'   => 'Thanks! Your request has been received. Our team will get back to you shortly.',
        ];
    }

    /** The tenant's default priority name, or the first configured one. */
    private function defaultPriorityName(int $tenantId): string
    {
        $priorities = TicketPriority::forTenant($tenantId)->orderBy('order')->get(['name', 'is_default']);

        return $priorities->firstWhere('is_default', true)?->name
            ?? $priorities->first()?->name
            ?? 'medium';
    }

    /* ── Origin allowlist (BUG-07) ──────────────────────────────────
     *
     * `allowed_origin` is a comma-separated list of HOSTNAMES ("example.com,
     * www.example.com") — a list, because one widget is routinely embedded on
     * several sites, and hostnames because that is the only part of an Origin a
     * browser guarantees. Matching is exact and case-insensitive; there is no
     * substring or pattern matching, so "evil-example.com.attacker.net" can
     * never satisfy an "example.com" rule.
     *
     * Blank = allow any site. That is a deliberate product choice, not an
     * oversight: the widget must work the moment it is embedded, before the
     * admin has configured anything, and every widget row that exists today has
     * a null allowed_origin. The controller documents it in the same terms.
     *
     * The tightening: once an allowlist IS configured, a request that carries no
     * Origin/Referer at all is now rejected. It previously sailed through — the
     * old guard required BOTH a configured origin AND a present origin header —
     * which made the allowlist trivially bypassable by any non-browser client
     * that simply omitted the header.
     */
    private function assertOriginAllowed(HelpdeskWidgetSetting $settings, ?string $origin): void
    {
        $allowed = self::parseOriginList((string) $settings->allowed_origin);

        if (! $allowed) {
            return;   // blank = allow all (documented above)
        }

        if (! $origin || ! $this->originAllowed($allowed, $origin)) {
            throw new BusinessException('This widget is not permitted on this site.', 403);
        }
    }

    /** @param array<string> $allowed canonical lowercase hostnames */
    private function originAllowed(array $allowed, string $origin): bool
    {
        $host = self::hostOf($origin);

        return $host !== '' && in_array($host, $allowed, true);
    }

    /**
     * Split an admin-entered allowlist into canonical lowercase hostnames.
     * Tolerant of what people actually paste: "https://example.com/support",
     * "example.com:8080", stray spaces and empty entries.
     *
     * @return array<string>
     */
    public static function parseOriginList(string $raw): array
    {
        return collect(explode(',', $raw))
            ->map(fn ($entry) => self::hostOf($entry))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** Reduce an origin/URL/host entry to a bare lowercase hostname ('' if unusable). */
    public static function hostOf(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        // parse_url only finds a host when there's a scheme; add one if absent.
        $candidate = Str::contains($value, '://') ? $value : 'http://'.$value;
        $host = parse_url($candidate, PHP_URL_HOST);

        return $host ? strtolower($host) : '';
    }

    /**
     * Is this a syntactically valid hostname? Used by the controller to validate
     * what an admin types. Deliberately rejects wildcards and regex/partial hosts
     * ("*.example.com", ".example.com", "example") — the matcher is exact, so
     * accepting a pattern would silently create a rule that can never match.
     */
    public static function isValidHostname(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }

        // A single label is only legitimate for local development.
        if (! Str::contains($host, '.')) {
            return in_array($host, ['localhost'], true);
        }

        return (bool) preg_match(
            '/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/i',
            $host
        );
    }
}
