<?php

namespace Tests\Feature\Helpdesk;

use App\Models\Helpdesk\HelpdeskWidgetSetting;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The shareable support link — SIR-000025.
 *
 * "Public link ticket is not available to share that link to anyone or place
 * that link to website." The widget existed only as a <script> snippet, which is
 * no use to somebody who cannot edit the page's markup.
 *
 * The new page at /support/:key is a thin client over THIS endpoint, so what is
 * worth pinning is the endpoint's contract: the key selects the tenant, the
 * reference comes back for the person to quote, and the protections that make it
 * safe to hand out publicly still hold.
 */
class PublicSupportLinkTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Sangoe OS', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function widget(bool $enabled = true): HelpdeskWidgetSetting
    {
        return HelpdeskWidgetSetting::create([
            'tenant_id' => self::TENANT,
            'public_key' => 'wk_'.bin2hex(random_bytes(8)),
            'is_enabled' => $enabled,
        ]);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'name' => 'Priya Sharma',
            'email' => 'priya@example.com',
            'subject' => 'Cannot download my invoice',
            'message' => 'The download button spins and nothing happens.',
        ], $over);
    }

    /* ── the happy path the page depends on ──────────────────────────── */

    public function test_anyone_with_the_link_can_raise_a_ticket_without_logging_in(): void
    {
        $w = $this->widget();

        $res = $this->postJson("/api/helpdesk/public/widget/{$w->public_key}/tickets", $this->payload())
            ->assertCreated();

        // The page shows this back to the reporter as their reference, so it has
        // to actually be there — a null would render an empty confirmation.
        $reference = $res->json('data.reference') ?? $res->json('reference');
        $this->assertNotNull($reference, 'the submitter must be given a reference to quote');

        $this->assertDatabaseHas('tickets', [
            'tenant_id' => self::TENANT,
            'subject' => 'Cannot download my invoice',
        ]);
    }

    /** The ticket has to land in the tenant the KEY names, not somewhere else. */
    public function test_the_key_decides_which_tenant_receives_the_ticket(): void
    {
        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'Other Co', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();

        $other = HelpdeskWidgetSetting::create([
            'tenant_id' => 2, 'public_key' => 'wk_'.bin2hex(random_bytes(8)), 'is_enabled' => true,
        ]);

        $this->postJson("/api/helpdesk/public/widget/{$other->public_key}/tickets", $this->payload())
            ->assertCreated();

        $this->assertDatabaseHas('tickets', ['tenant_id' => 2, 'subject' => 'Cannot download my invoice']);
        $this->assertDatabaseMissing('tickets', ['tenant_id' => self::TENANT]);
    }

    /* ── and the reasons it is safe to hand out ──────────────────────── */

    /**
     * Turning the widget off has to turn the link off with it.
     *
     * They are one key, and the Widget screen says so — if the link kept working
     * after the toggle, that screen would be lying.
     */
    public function test_a_disabled_widget_refuses_the_link_too(): void
    {
        $w = $this->widget(enabled: false);

        $res = $this->postJson("/api/helpdesk/public/widget/{$w->public_key}/tickets", $this->payload());

        $this->assertGreaterThanOrEqual(400, $res->getStatusCode(),
            'a disabled widget must not keep accepting tickets through the URL');
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_an_unknown_key_creates_nothing(): void
    {
        $res = $this->postJson('/api/helpdesk/public/widget/wk_nosuchkey/tickets', $this->payload());

        $this->assertGreaterThanOrEqual(400, $res->getStatusCode());
        $this->assertDatabaseCount('tickets', 0);
    }

    /**
     * The honeypot the form carries.
     *
     * The field is hidden and out of the tab order, so a filled one is a bot. The
     * page must keep rendering it for this to matter.
     */
    public function test_a_filled_honeypot_is_rejected(): void
    {
        $w = $this->widget();

        $this->postJson("/api/helpdesk/public/widget/{$w->public_key}/tickets",
            $this->payload(['hp' => 'buy-cheap-things']))
            ->assertStatus(422);

        $this->assertDatabaseCount('tickets', 0);
    }

    /** Required fields are required — the page marks all four. */
    public function test_the_four_fields_the_form_marks_required_really_are(): void
    {
        $w = $this->widget();

        foreach (['name', 'email', 'subject', 'message'] as $field) {
            $body = $this->payload();
            unset($body[$field]);

            $this->postJson("/api/helpdesk/public/widget/{$w->public_key}/tickets", $body)
                ->assertStatus(422, "{$field} must be required");
        }

        $this->assertDatabaseCount('tickets', 0);
    }
}
