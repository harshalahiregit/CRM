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

        // ── Knowledge Base: 3 categories × 2 sub-categories × 2 articles ──
        $tree = [
            'Getting Started' => [
                'Setup'  => ['Installing the app', 'Your first login'],
                'Basics' => ['Navigating the dashboard', 'Inviting your team'],
            ],
            'Billing & Plans' => [
                'Invoices' => ['Understanding your invoice', 'Downloading past invoices'],
                'Payments' => ['Adding a payment method', 'Our refund policy'],
            ],
            'Troubleshooting' => [
                'Login Issues' => ['Reset your password', 'Two-factor authentication problems'],
                'Performance'  => ['Reports are slow to load', 'Clearing your cache'],
            ],
        ];

        $articleCount = 0;
        $published = 0;
        foreach ($tree as $catName => $subs) {
            $cat = KbCategory::create(['tenant_id' => $tenantId, 'name' => $catName, 'slug' => Str::slug($catName)]);
            foreach ($subs as $subName => $titles) {
                $sub = KbSubcategory::create(['tenant_id' => $tenantId, 'category_id' => $cat->id, 'name' => $subName, 'slug' => Str::slug($subName)]);
                foreach ($titles as $title) {
                    $isPub = fake()->boolean(65);
                    KbArticle::create([
                        'tenant_id'      => $tenantId,
                        'category_id'    => $cat->id,
                        'subcategory_id' => $sub->id,
                        'title'          => $title,
                        'excerpt'        => fake()->sentence(10),
                        'content'        => '<h2>'.$title.'</h2><p>'.fake()->paragraph().'</p>'
                                            .'<ol><li>'.fake()->sentence().'</li><li>'.fake()->sentence().'</li><li>'.fake()->sentence().'</li></ol>'
                                            .'<p>'.fake()->paragraph().'</p>',
                        'is_published'   => $isPub,
                        'public_slug'    => $isPub ? Str::slug($title).'-'.Str::lower(Str::random(6)) : null,
                        'published_at'   => $isPub ? $now->copy()->subDays(rand(1, 20)) : null,
                        'thumbs_up'      => fake()->numberBetween(2, 60),
                        'thumbs_down'    => fake()->numberBetween(0, 10),
                    ]);
                    $articleCount++;
                    $published += $isPub ? 1 : 0;
                }
            }
        }

        $this->command->info("Helpdesk demo data seeded for tenant #{$tenantId}:");
        $this->command->info("  {$ticketCount} tickets ({$reopened} reopened), KB: 3 categories / 6 sub-categories / {$articleCount} articles ({$published} published)");
        if (! $hasCustomers) {
            $this->command->warn('  Note: no `customers` table yet — customer_id uses mock roster IDs resolved via CustomerServiceContract (schema-ready, not real integration).');
        }
    }
}
