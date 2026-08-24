<?php

namespace Tests\Feature\Notifications;

use App\Models\Tenant;
use App\Services\Notifications\NotificationService;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The notification preferences grid now governs delivery.
 *
 * It always wrote a five-channel master row plus an 11-category matrix into the
 * `notifications` setting group, reported "Notification preferences saved", and
 * was read by nothing at all — NotificationService dispatched unconditionally.
 * So switching Email off for Payroll changed nothing, the switch stayed off on
 * reload, and the admin had every reason to believe it had taken effect.
 *
 * These assert the read side, and specifically the two ways it must NOT behave:
 * it must not suppress security mail, and it must not suppress anything when it
 * cannot read the preferences.
 */
class NotificationPreferencesAreHonouredTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private NotificationService $notifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'subdomain' => 'acme',
            'plan' => 'professional', 'status' => 'active',
        ]);
        $this->notifier = app(NotificationService::class);
        Mail::fake();
    }

    private function setPrefs(array $values): void
    {
        app(SettingsService::class)->setGroup($this->tenant->id, 'notifications', $values);
    }

    // ── the switches do something ─────────────────────────────────────────────

    public function test_a_category_channel_switched_off_suppresses_that_notification(): void
    {
        $matrix = \App\Support\Settings\SettingRegistry::notificationMatrix();
        $matrix['Payroll']['email'] = false;
        $this->setPrefs(['categories' => $matrix]);

        $result = $this->notifier->email(
            'e@x.test', 'Payslip', 'body',
            ['category' => 'Payroll'], $this->tenant->id,
        );

        $this->assertSame('suppressed', $result);
    }

    public function test_another_category_is_untouched_by_that_switch(): void
    {
        $matrix = \App\Support\Settings\SettingRegistry::notificationMatrix();
        $matrix['Payroll']['email'] = false;
        $this->setPrefs(['categories' => $matrix]);

        $this->assertNotSame('suppressed', $this->notifier->email(
            'e@x.test', 'Ticket update', 'body', ['category' => 'Helpdesk'], $this->tenant->id,
        ), 'turning Payroll off must not silence Helpdesk');
    }

    public function test_the_master_channel_switch_suppresses_every_category(): void
    {
        $this->setPrefs(['email' => false]);

        foreach (['Payroll', 'Helpdesk', 'Sales'] as $category) {
            $this->assertSame('suppressed', $this->notifier->email(
                'e@x.test', 'Anything', 'body', ['category' => $category], $this->tenant->id,
            ), "master email=off must cover {$category}");
        }
    }

    public function test_it_applies_to_the_html_and_stub_channels_too(): void
    {
        $this->setPrefs(['email' => false, 'whatsapp' => false, 'sms' => false]);

        $this->assertSame('suppressed', $this->notifier->emailHtml(
            'e@x.test', 'S', '<p>h</p>', ['category' => 'Sales'], null, $this->tenant->id));
        $this->assertSame('suppressed', $this->notifier->whatsapp(
            '+919999999999', 'b', ['category' => 'Sales', 'tenant_id' => $this->tenant->id]));
    }

    // ── the ways it must NOT behave ───────────────────────────────────────────

    public function test_security_mail_is_delivered_even_when_switched_off(): void
    {
        // An admin must not be able to lock the workspace out of its own
        // password resets from a preferences grid.
        $matrix = \App\Support\Settings\SettingRegistry::notificationMatrix();
        $matrix['Security']['email'] = false;
        $this->setPrefs(['email' => false, 'categories' => $matrix]);

        $this->assertNotSame('suppressed', $this->notifier->email(
            'e@x.test', 'Reset your password', 'link', ['category' => 'Security'], $this->tenant->id,
        ));
    }

    public function test_a_notification_with_no_category_is_always_delivered(): void
    {
        // Transactional mail — an acknowledgement for something the user just
        // did — is not what this grid governs, and dropping it would break a
        // flow the user started.
        $this->setPrefs(['email' => false]);

        $this->assertNotSame('suppressed', $this->notifier->email(
            'e@x.test', 'We received your request', 'body', [], $this->tenant->id,
        ));
    }

    public function test_defaults_deliver_when_the_admin_has_changed_nothing(): void
    {
        foreach (['Payroll', 'HR', 'Sales'] as $category) {
            $this->assertNotSame('suppressed', $this->notifier->email(
                'e@x.test', 'Hello', 'body', ['category' => $category], $this->tenant->id,
            ), 'a fresh tenant must still get its email');
        }
    }

    public function test_an_unknown_category_delivers_rather_than_being_dropped(): void
    {
        // A caller naming a category the grid does not cover must not be silently
        // silenced — the grid cannot express an opinion about it.
        $this->assertNotSame('suppressed', $this->notifier->email(
            'e@x.test', 'Hello', 'body', ['category' => 'SomethingNew'], $this->tenant->id,
        ));
    }

    public function test_it_fails_open_when_there_is_no_tenant_context(): void
    {
        // Queued / console work with no tenant has nothing to consult.
        $this->assertNotSame('suppressed', $this->notifier->email(
            'e@x.test', 'Hello', 'body', ['category' => 'Payroll'], null,
        ));
    }
}
