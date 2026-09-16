<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Notifications\HrNotification;
use App\Models\Notifications\HrNotificationQueueItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\RequestNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * That a My Services notification actually leaves the building.
 *
 * It did not. RequestNotifier dispatched under the module 'HR', nothing is
 * registered under 'HR', and the engine's rule for an unregistered event is to
 * skip SILENTLY — so every leave approval, expense decision, advance and
 * attendance correction wrote its in-app row and then vanished. No queue item,
 * no push, no email, no WhatsApp. Nothing threw, nothing logged, and the app's
 * bell still lit up, which is exactly why it went unnoticed.
 *
 * These tests assert the queue item exists, because that is the thing whose
 * absence was invisible.
 */
class RequestNotifierDeliversTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private HrEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 'rn-t', 'status' => 'active']);
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Priya', 'email' => 'p@example.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]);
        $this->employee = HrEmployee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Priya', 'employee_code' => 'E1',
            'department' => 'Ops', 'designation' => 'Executive', 'status' => 'Active',
            'joining_date' => '2020-01-01', 'user_id' => $user->id,
        ]);
    }

    private function tell(string $kind, string $event): void
    {
        app(RequestNotifier::class)->tell($this->employee, $kind, $event, 'Something happened.');
    }

    public static function requestKinds(): array
    {
        return [
            'leave approved'      => ['Leave', 'approved', 'Leave'],
            'leave submitted'     => ['Leave', 'submitted', 'Leave'],
            'expense declined'    => ['Expense Claim', 'declined', 'Expense'],
            'expense paid'        => ['Expense Claim', 'paid out', 'Expense'],
            'advance part'        => ['Advance', 'part-approved', 'Advance'],
            'correction rejected' => ['Attendance Correction', 'rejected', 'Attendance'],
        ];
    }

    /** @dataProvider requestKinds */
    public function test_every_request_kind_produces_a_deliverable_notification(string $kind, string $event, string $module): void
    {
        $this->tell($kind, $event);

        $n = HrNotification::first();

        $this->assertNotNull($n, "No notification for {$kind}/{$event} — the engine skipped it silently");
        $this->assertSame($module, $n->module, 'The module decides where a tap lands and which template is used');
        $this->assertSame($event, $n->event);

        // The half that was missing: without a queue item nothing is ever sent.
        $this->assertGreaterThan(0, HrNotificationQueueItem::where('notification_id', $n->id)->count());
    }

    public function test_the_channels_include_push_so_the_phone_hears_about_it(): void
    {
        $this->tell('Leave', 'approved');

        $channels = HrNotificationQueueItem::pluck('channel')->all();

        $this->assertContains('in_app', $channels);
        $this->assertContains('push', $channels);
    }

    public function test_whatsapp_is_not_queued_until_the_workspace_asks_for_it(): void
    {
        $this->tell('Leave', 'approved');

        // Off in the registry default. Every WhatsApp message costs the tenant
        // money, so this must never switch itself on.
        $this->assertNotContains('whatsapp', HrNotificationQueueItem::pluck('channel')->all());
    }

    public function test_whatsapp_is_queued_once_the_workspace_turns_it_on(): void
    {
        $settings = app(\App\Services\Settings\SettingsService::class);
        // Two switches, both of which must be on: the channel's master switch
        // and the HR row of the category grid. The master defaults to off, so
        // ticking only the grid row changes nothing — which is its own trap.
        $settings->set($this->tenant->id, 'notifications', 'whatsapp', true);
        $settings->set($this->tenant->id, 'notifications', 'categories',
            ['HR' => ['email' => true, 'browser' => true, 'whatsapp' => true, 'sms' => false, 'push' => true]]);

        $this->tell('Leave', 'approved');

        $this->assertContains('whatsapp', HrNotificationQueueItem::pluck('channel')->all());
    }

    public function test_an_employee_with_no_login_is_skipped_without_throwing(): void
    {
        $orphan = HrEmployee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'No Login', 'employee_code' => 'E2',
            'department' => 'Ops', 'designation' => 'Executive', 'status' => 'Active',
            'joining_date' => '2020-01-01',
        ]);

        // Plenty of people are on the payroll and not on the app.
        app(RequestNotifier::class)->tell($orphan, 'Leave', 'approved', 'x');

        $this->assertSame(0, HrNotification::count());
    }

    public function test_an_unregistered_module_still_notifies_nobody(): void
    {
        // The catch-all is per module and opt-in. Something inventing its own
        // kind must not start mailing the company.
        $this->tell('Sabbatical', 'granted');

        $this->assertSame(0, HrNotification::count());
    }
}
