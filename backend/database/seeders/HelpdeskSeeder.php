<?php

namespace Database\Seeders;

use App\Models\Helpdesk\KbArticle;
use App\Models\Helpdesk\KbCategory;
use App\Models\Helpdesk\KbSubcategory;
use App\Models\Helpdesk\Ticket;
use App\Models\Helpdesk\TicketFeedback;
use App\Models\Helpdesk\TicketReply;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Realistic Helpdesk demo data (owner: Shivam).
 *
 * Integration honesty:
 *  - assigned_to → real users (shared auth table): true integration.
 *  - customer_id → Zafar's Customer module is NOT built yet (no `customers`
 *    table). We link some tickets to the mock roster IDs (1–3) that
 *    MockCustomerService resolves, so the customer column populates and the
 *    service-contract path is exercised — but this is SCHEMA-READY, not a real
 *    cross-module integration test. Swap to real customer_ids once that module ships.
 *
 * Only touches Helpdesk-owned tables (+ creates a few support-agent users).
 */
class HelpdeskSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::first();
        if (! $tenant) {
            $this->command->warn('HelpdeskSeeder: no tenant found — run the main seeder first.');
            return;
        }
        $tenantId = $tenant->id;

        // Reset Helpdesk-owned data for reproducible metrics (module tables only).
        TicketFeedback::where('tenant_id', $tenantId)->delete();
        DB::table('ticket_attachments')->whereIn('reply_id', TicketReply::where('tenant_id', $tenantId)->pluck('id'))->delete();
        TicketReply::where('tenant_id', $tenantId)->delete();
        Ticket::where('tenant_id', $tenantId)->forceDelete();
        KbArticle::where('tenant_id', $tenantId)->delete();
        KbSubcategory::where('tenant_id', $tenantId)->delete();
        KbCategory::where('tenant_id', $tenantId)->delete();

        // Support agents (idempotent) so the by-assignee chart has real spread.
        $agentIds = collect(['Priya Sharma', 'Rohit Verma', 'Anjali Singh'])
            ->map(fn ($name) => User::firstOrCreate(
                ['email' => Str::slug($name, '.').'@mlacrm.com'],
                ['tenant_id' => $tenantId, 'name' => $name, 'password' => Hash::make('Agent@12345'), 'role' => 'staff', 'status' => 'active'],
            )->id)->all();

        $admin = User::where('tenant_id', $tenantId)->where('role', 'admin')->first();
        $assigneePool = array_merge([$admin->id], $agentIds, $agentIds, [null]); // weighted toward agents, some unassigned
        $agentReplyIds = array_merge([$admin->id], $agentIds);

        $hasCustomers = Schema::hasTable('customers') && DB::table('customers')->exists();
        $customerIds = $hasCustomers ? DB::table('customers')->pluck('id')->all() : [1, 2, 3]; // mock roster

        $subjects = [
            'Cannot log in after password reset', 'Invoice total looks incorrect', 'Feature request: dark mode',
            'Export to CSV is failing', 'Dashboard charts not loading on mobile', 'Email notifications arriving twice',
            'How do I add a team member?', 'Payment declined but card is valid', 'App is slow in the afternoon',
            'Need to change billing address', 'Two-factor code never arrives', 'Report shows wrong date range',
            'Attachment upload stuck at 90%', 'Requesting a refund for last month', 'Integration webhook not firing',
            'Account locked after failed logins', 'Data not syncing across devices', 'Typo on the settings page',
            'Cannot delete an old project', 'Notification sound too loud',
        ];
        $priorities = ['low', 'medium', 'high', 'urgent'];

        $now = now();
        $ticketCount = 0;
        $reopened = 0;

        for ($i = 0; $i < 20; $i++) {
            $created = $now->copy()->subDays(rand(0, 30))->subHours(rand(0, 23));

            // First 3 are "closed then reopened" (client replied on a closed ticket).
            $isReopen = $i < 3;
            $status = $isReopen ? 'open' : fake()->randomElement(['open', 'open', 'in-progress', 'closed', 'closed']);
            $isWidget = fake()->boolean(20);

            $closedAt = $status === 'closed' ? $created->copy()->addHours(rand(3, 120)) : null;

            $ticket = new Ticket();
            $ticket->forceFill([
                'tenant_id'       => $tenantId,
                'subject'         => $subjects[$i % count($subjects)],
                'description'     => fake()->paragraph(),
                'status'          => $status,
                'priority'        => fake()->randomElement($priorities),
                'assigned_to'     => fake()->randomElement($assigneePool),
                'customer_id'     => fake()->boolean(55) ? fake()->randomElement($customerIds) : null,
                'due_date'        => fake()->boolean(60) ? $created->copy()->addDays(rand(1, 12)) : null,
                'source'          => $isWidget ? 'widget' : 'internal',
                'requester_name'  => $isWidget ? fake()->name() : null,
                'requester_email' => $isWidget ? fake()->safeEmail() : null,
                'created_at'      => $created,
                'updated_at'      => $closedAt ?? $created,
            ]);
            $ticket->timestamps = false;
            $ticket->save();
            $ticket->timestamps = true;
            $ticketCount++;

            // Conversation thread — alternating client/agent, ascending in time.
            $replyTime = $created->copy();
            $replyN = rand(3, 7);
            for ($r = 0; $r < $replyN; $r++) {
                $replyTime = $replyTime->copy()->addMinutes(rand(30, 600));
                if ($closedAt && $replyTime->gt($closedAt)) {
                    $replyTime = $closedAt->copy()->subMinutes(rand(5, 60));
                }
                $isClient = $r % 2 === 0;
                $reply = new TicketReply();
                $reply->forceFill([
                    'tenant_id'       => $tenantId,
                    'ticket_id'       => $ticket->id,
                    'sender_type'     => $isClient ? 'client' : 'agent',
                    'sender_id'       => $isClient ? $ticket->customer_id : fake()->randomElement($agentReplyIds),
                    'message'         => fake()->sentence(rand(6, 18)),
                    'has_attachments' => false,
                    'created_at'      => $replyTime,
                    'updated_at'      => $replyTime,
                ]);
                $reply->timestamps = false;
                $reply->save();
                $reply->timestamps = true;
            }

            // A reopened ticket gets a final client reply AFTER a prior closure.
            if ($isReopen) {
                $reply = new TicketReply();
                $reply->forceFill([
                    'tenant_id' => $tenantId, 'ticket_id' => $ticket->id,
                    'sender_type' => 'client', 'sender_id' => $ticket->customer_id,
                    'message' => 'This is still happening — please reopen.',
                    'has_attachments' => false,
                    'created_at' => $now->copy()->subDays(rand(0, 3)), 'updated_at' => $now->copy()->subDays(rand(0, 3)),
                ]);
                $reply->timestamps = false;
                $reply->save();
                $reply->timestamps = true;
                $reopened++;
            }

            // CSAT on most closed tickets.
            if ($status === 'closed' && fake()->boolean(75)) {
                $fb = new TicketFeedback();
                $fb->forceFill([
                    'tenant_id' => $tenantId, 'ticket_id' => $ticket->id,
                    'rating' => fake()->numberBetween(3, 5),
                    'comments' => fake()->boolean(50) ? fake()->sentence() : null,
                    'created_at' => $closedAt, 'updated_at' => $closedAt,
                ]);
                $fb->timestamps = false;
                $fb->save();
                $fb->timestamps = true;
            }
        }

        // ── Knowledge Base: genuine, professional support articles ──
        $tree = $this->kbContent();

        $articleCount = 0;
        $published = 0;
        foreach ($tree as $catName => $subs) {
            $cat = KbCategory::create(['tenant_id' => $tenantId, 'name' => $catName, 'slug' => Str::slug($catName)]);
            foreach ($subs as $subName => $articles) {
                $sub = KbSubcategory::create(['tenant_id' => $tenantId, 'category_id' => $cat->id, 'name' => $subName, 'slug' => Str::slug($subName)]);
                foreach ($articles as $art) {
                    $title = $art['title'];
                    KbArticle::create([
                        'tenant_id'      => $tenantId,
                        'category_id'    => $cat->id,
                        'subcategory_id' => $sub->id,
                        'title'          => $title,
                        'excerpt'        => $art['excerpt'],
                        'content'        => $art['content'],
                        'is_published'   => true,
                        'public_slug'    => Str::slug($title).'-'.Str::lower(Str::random(6)),
                        'published_at'   => $now->copy()->subDays(rand(1, 40)),
                        'thumbs_up'      => fake()->numberBetween(8, 120),
                        'thumbs_down'    => fake()->numberBetween(0, 6),
                    ]);
                    $articleCount++;
                    $published++;
                }
            }
        }

        $this->command->info("Helpdesk demo data seeded for tenant #{$tenantId}:");
        $this->command->info("  {$ticketCount} tickets ({$reopened} reopened), KB: 3 categories / 6 sub-categories / {$articleCount} articles ({$published} published)");
        if (! $hasCustomers) {
            $this->command->warn('  Note: no `customers` table yet — customer_id uses mock roster IDs resolved via CustomerServiceContract (schema-ready, not real integration).');
        }
    }

    /**
     * Genuine, professional help-centre articles (3 categories × 2 sub-cats × 2 articles).
     * Content uses semantic HTML (h2/h3/p/ol/ul/blockquote) styled by the .kb-read / .pub-prose rules.
     */
    private function kbContent(): array
    {
        return [
            'Getting Started' => [
                'Setup' => [
                    [
                        'title'   => 'Installing the Sangoe desktop app',
                        'excerpt' => 'Download and install Sangoe on Windows or macOS in under five minutes.',
                        'content' => <<<'HTML'
<h2>Before you begin</h2>
<p>Sangoe runs in any modern browser, but the desktop app adds native notifications, faster launch, and offline drafts. You will need administrator rights on your computer and about 300&nbsp;MB of free disk space.</p>
<h3>Install on Windows</h3>
<ol>
<li>Go to <strong>Settings → Downloads</strong> and choose <em>Windows (.exe)</em>.</li>
<li>Open the downloaded installer and accept the security prompt.</li>
<li>Follow the wizard and launch Sangoe when it finishes.</li>
</ol>
<h3>Install on macOS</h3>
<ol>
<li>Download the <em>macOS (.dmg)</em> build.</li>
<li>Drag the Sangoe icon into your <strong>Applications</strong> folder.</li>
<li>Right-click the app and choose <em>Open</em> the first time to bypass Gatekeeper.</li>
</ol>
<blockquote>Tip: The desktop app updates itself automatically — you never need to reinstall for a new version.</blockquote>
HTML,
                    ],
                    [
                        'title'   => 'Signing in for the first time',
                        'excerpt' => 'Activate your account, set a strong password, and turn on two-factor authentication.',
                        'content' => <<<'HTML'
<h2>Activate your invitation</h2>
<p>When your administrator adds you, Sangoe emails an invitation link that is valid for 72&nbsp;hours. Clicking it brings you to the activation screen.</p>
<ol>
<li>Enter your full name as you want it shown to teammates.</li>
<li>Create a password with at least 10 characters, including a number and a symbol.</li>
<li>Choose <strong>Enable two-factor authentication</strong> and scan the QR code with any authenticator app.</li>
</ol>
<h3>If your link expired</h3>
<p>Ask your administrator to resend the invite from <strong>Staff Management</strong>, or use <em>Forgot password</em> on the login page to receive a fresh link.</p>
<blockquote>Security tip: Never reuse a password from another service. Sangoe protects business data, so a unique password matters.</blockquote>
HTML,
                    ],
                ],
                'Basics' => [
                    [
                        'title'   => 'A quick tour of your dashboard',
                        'excerpt' => 'Understand the sidebar, top bar, widgets, and the ⌘K command palette.',
                        'content' => <<<'HTML'
<h2>The three areas of Sangoe</h2>
<p>Every screen shares the same layout so you always know where things are.</p>
<ul>
<li><strong>Sidebar</strong> — switch between modules such as Helpdesk, Sales, Projects and HR.</li>
<li><strong>Top bar</strong> — global search, notifications, theme toggle, and your profile.</li>
<li><strong>Workspace</strong> — the content for the module you are in.</li>
</ul>
<h3>Move faster with the command palette</h3>
<p>Press <code>Ctrl</code>+<code>K</code> (or <code>⌘</code>+<code>K</code> on Mac) anywhere to jump to a ticket, customer, article or action without touching the mouse.</p>
<blockquote>Tip: Pin the modules you use most from <strong>Modules</strong> so they sit at the top of your sidebar.</blockquote>
HTML,
                    ],
                    [
                        'title'   => 'Inviting your team and setting roles',
                        'excerpt' => 'Add teammates, assign roles, and control what each person can see.',
                        'content' => <<<'HTML'
<h2>Add a teammate</h2>
<ol>
<li>Open <strong>Staff Management</strong> from the sidebar.</li>
<li>Click <strong>Invite member</strong> and enter their work email.</li>
<li>Pick a role — this decides which modules and actions they can access.</li>
</ol>
<h3>What each role can do</h3>
<ul>
<li><strong>Admin</strong> — full access, including settings and billing.</li>
<li><strong>Agent</strong> — works tickets and knowledge, but cannot change org settings.</li>
<li><strong>Viewer</strong> — read-only access to reports and records.</li>
</ul>
<p>You can change anyone's role later; the change takes effect the next time they load a page.</p>
HTML,
                    ],
                ],
            ],
            'Billing & Plans' => [
                'Invoices' => [
                    [
                        'title'   => 'Understanding your monthly invoice',
                        'excerpt' => 'A line-by-line explanation of charges, proration, and taxes.',
                        'content' => <<<'HTML'
<h2>How billing works</h2>
<p>Sangoe bills per active seat, once a month, on the date you first subscribed. Your invoice always covers the period shown at the top.</p>
<h3>Reading the line items</h3>
<ul>
<li><strong>Base plan</strong> — your plan price × number of seats.</li>
<li><strong>Proration</strong> — a partial charge or credit when you add or remove seats mid-cycle.</li>
<li><strong>Taxes</strong> — GST or local tax, calculated from your billing address.</li>
</ul>
<blockquote>Tip: Add a purchase-order number or VAT ID under <strong>Settings → Billing</strong> and it will appear on every future invoice.</blockquote>
HTML,
                    ],
                    [
                        'title'   => 'Downloading and sharing past invoices',
                        'excerpt' => 'Get a PDF of any invoice and send it to your finance team.',
                        'content' => <<<'HTML'
<h2>Find your invoices</h2>
<ol>
<li>Go to <strong>Settings → Billing → Invoice history</strong>.</li>
<li>Every invoice is listed newest first with its status.</li>
<li>Click <strong>Download PDF</strong> on any row.</li>
</ol>
<h3>Send invoices to finance automatically</h3>
<p>Add a billing-only email under <strong>Billing contacts</strong> and each new invoice is emailed there the moment it is issued — no manual forwarding needed.</p>
HTML,
                    ],
                ],
                'Payments' => [
                    [
                        'title'   => 'Adding or updating a payment method',
                        'excerpt' => 'Store a card securely and set which one is charged by default.',
                        'content' => <<<'HTML'
<h2>Add a card</h2>
<ol>
<li>Open <strong>Settings → Billing → Payment methods</strong>.</li>
<li>Click <strong>Add card</strong> and enter the details.</li>
<li>Choose <strong>Set as default</strong> if this card should be charged going forward.</li>
</ol>
<p>Card details are handled by our PCI-compliant payment processor — Sangoe never stores your full card number.</p>
<blockquote>Tip: Keep a backup card on file so a single expired card never interrupts your service.</blockquote>
HTML,
                    ],
                    [
                        'title'   => 'Our refund policy explained',
                        'excerpt' => 'When refunds apply, how to request one, and how long they take.',
                        'content' => <<<'HTML'
<h2>What qualifies for a refund</h2>
<p>We offer a full refund within 14&nbsp;days of your first payment, no questions asked. After that, monthly plans are non-refundable but you keep access until the end of the paid period.</p>
<h3>How to request a refund</h3>
<ol>
<li>Open a ticket from <strong>Help → Contact support</strong>.</li>
<li>Choose the <em>Billing</em> category and mention the invoice number.</li>
<li>Our team confirms within one business day.</li>
</ol>
<p>Approved refunds return to your original payment method within 5–10 business days, depending on your bank.</p>
HTML,
                    ],
                ],
            ],
            'Troubleshooting' => [
                'Login Issues' => [
                    [
                        'title'   => 'How to reset your password',
                        'excerpt' => 'Regain access in a couple of minutes using the reset link.',
                        'content' => <<<'HTML'
<h2>Reset from the login page</h2>
<ol>
<li>On the sign-in screen, click <strong>Forgot password?</strong></li>
<li>Enter your work email and submit.</li>
<li>Open the reset email and choose a new password.</li>
</ol>
<h3>Didn't get the email?</h3>
<ul>
<li>Check your spam or junk folder.</li>
<li>Make sure you used the same email your account was created with.</li>
<li>Wait two minutes — delivery can be briefly delayed.</li>
</ul>
<blockquote>Still stuck? Ask an administrator to trigger a reset for you from Staff Management.</blockquote>
HTML,
                    ],
                    [
                        'title'   => 'Fixing two-factor authentication problems',
                        'excerpt' => 'What to do when your codes are rejected or you lose your device.',
                        'content' => <<<'HTML'
<h2>Codes are being rejected</h2>
<p>Authenticator codes are time-based, so the most common cause is a clock that is out of sync.</p>
<ol>
<li>Open your authenticator app's settings and enable <strong>automatic time sync</strong>.</li>
<li>Make sure your phone's date &amp; time is set to automatic.</li>
<li>Try the next freshly generated code.</li>
</ol>
<h3>Lost your device</h3>
<p>Use one of the backup codes you saved when you set up 2FA. If you have none, an administrator can reset your two-factor from <strong>Staff Management → member → Reset 2FA</strong>.</p>
HTML,
                    ],
                ],
                'Performance' => [
                    [
                        'title'   => 'Why reports load slowly (and how to fix it)',
                        'excerpt' => 'Practical steps to speed up heavy dashboards and exports.',
                        'content' => <<<'HTML'
<h2>Common causes</h2>
<p>Most slow reports come from a very wide date range or too many grouped columns loading at once.</p>
<ol>
<li>Narrow the date range to what you actually need.</li>
<li>Remove grouping columns you are not using.</li>
<li>Save the filtered view so it loads the same way next time.</li>
</ol>
<h3>Still slow?</h3>
<p>Try a hard refresh (<code>Ctrl</code>+<code>Shift</code>+<code>R</code>) and confirm your connection is stable. If a specific report is consistently slow, send us its name and filters and we will investigate.</p>
HTML,
                    ],
                    [
                        'title'   => 'Clearing your browser cache',
                        'excerpt' => 'Fix visual glitches and stale data by clearing cached files.',
                        'content' => <<<'HTML'
<h2>When to clear the cache</h2>
<p>If the interface looks broken after an update, or old data lingers after a change, a cache clear usually fixes it.</p>
<h3>Chrome &amp; Edge</h3>
<ol>
<li>Press <code>Ctrl</code>+<code>Shift</code>+<code>Delete</code>.</li>
<li>Choose <strong>Cached images and files</strong>.</li>
<li>Click <strong>Clear data</strong> and reload Sangoe.</li>
</ol>
<h3>Safari</h3>
<ol>
<li>Open <strong>Safari → Settings → Advanced</strong> and enable the Develop menu.</li>
<li>Choose <strong>Develop → Empty Caches</strong>.</li>
</ol>
<blockquote>Tip: A hard refresh with <code>Ctrl</code>+<code>Shift</code>+<code>R</code> often works without clearing everything.</blockquote>
HTML,
                    ],
                ],
            ],
        ];
    }
}
