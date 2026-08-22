<?php

namespace App\Services\Customer;

use App\Exceptions\BusinessException;
use App\Models\Customer\Client;
use App\Models\Customer\ClientContact;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a customer contact may see of their own account.
 *
 * Two rules govern everything here, and both are security boundaries rather
 * than preferences:
 *
 *  1. Every query is scoped to the contact's OWN client. There is no endpoint
 *     that takes a client id from the caller, so no amount of tampering reaches
 *     another customer's data.
 *  2. Every section is gated on that contact's permission flags — the six the
 *     old CRM had (invoice, estimate, contract, proposal, support, project),
 *     which Sangoe modelled on client_contacts.permissions and never enforced.
 *
 * What is deliberately NOT exposed:
 *   • Customer Health and Risk — an internal management judgement about the
 *     customer. Showing someone their own risk rating would be extraordinary.
 *   • Credentials (the vault) — it stores OUR access to their systems.
 *   • Internal notes — only notes explicitly marked visibility='client'.
 *   • Files not marked customer_visible.
 */
class ClientPortalService
{
    /** Mirrors the old CRM's six contact permissions. */
    public const PERMISSIONS = ['invoice', 'estimate', 'contract', 'proposal', 'support', 'project'];

    public function can(ClientContact $contact, string $permission): bool
    {
        $granted = $contact->permissions;

        // A contact with no permissions array is a legacy row from before the
        // portal existed. Treating that as "everything" would silently hand out
        // access nobody granted, so the safe reading is "nothing".
        if (! is_array($granted)) {
            return false;
        }

        return in_array($permission, $granted, true);
    }

    public function assertCan(ClientContact $contact, string $permission): void
    {
        if (! $this->can($contact, $permission)) {
            throw new BusinessException('You do not have access to this section.', 403);
        }
    }

    /**
     * The portal landing page.
     *
     * The old CRM showed a greeting, a projects summary and an invoice figure.
     * This keeps that shape and adds what a customer actually asks about:
     * what is outstanding, what is overdue, and what is still open on their side.
     */
    public function dashboard(ClientContact $contact, Client $client): array
    {
        $fin = $this->financeSummary($client);

        return [
            'greeting'   => $this->greeting(),
            'contact'    => [
                'name'       => trim(($contact->first_name ?? '').' '.($contact->last_name ?? '')),
                'title'      => $contact->title,
                'last_login' => $contact->last_login_at?->toIso8601String(),
            ],
            'company'    => [
                'name'     => $client->company,
                'currency' => $client->default_currency ?: 'INR',
            ],
            'finance'    => $this->can($contact, 'invoice') ? $fin : null,
            'projects'   => $this->can($contact, 'project') ? $this->projectSummary($client) : null,
            'tickets'    => $this->can($contact, 'support') ? $this->ticketSummary($client) : null,
            // What the customer can act on, rather than what we think of them.
            'actions'    => $this->openActions($contact, $client, $fin),
            'permissions' => array_values(array_filter(
                self::PERMISSIONS,
                fn ($p) => $this->can($contact, $p)
            )),
        ];
    }

    private function greeting(): string
    {
        $h = (int) now()->format('G');

        return $h < 12 ? 'Good morning' : ($h < 17 ? 'Good afternoon' : 'Good evening');
    }

    /** Outstanding is the sum of unpaid balances, not of totals. */
    private function financeSummary(Client $client): array
    {
        if (! Schema::hasTable('sales_invoices')) {
            return ['outstanding' => 0.0, 'overdue' => 0, 'overdue_amount' => 0.0, 'paid_last_12m' => 0.0];
        }

        $rows = DB::table('sales_invoices')
            ->whereNull('deleted_at')
            ->where('tenant_id', $client->tenant_id)
            ->where('client_id', $client->id)
            ->whereNotIn('status', ['Draft', 'Cancelled'])
            ->get(['balance', 'paid', 'due_date', 'date']);

        $today = now()->toDateString();
        $overdue = $rows->filter(fn ($r) => (float) $r->balance > 0 && $r->due_date && $r->due_date < $today);

        return [
            'outstanding'    => round((float) $rows->sum('balance'), 2),
            'overdue'        => $overdue->count(),
            'overdue_amount' => round((float) $overdue->sum('balance'), 2),
            'paid_last_12m'  => round((float) $rows->where('date', '>=', now()->subYear()->toDateString())->sum('paid'), 2),
        ];
    }

    private function projectSummary(Client $client): array
    {
        if (! Schema::hasTable('projects')) {
            return ['active' => 0, 'finished' => 0];
        }

        $rows = DB::table('projects')
            ->whereNull('deleted_at')
            ->where('tenant_id', $client->tenant_id)
            ->where('customer_id', $client->id)
            ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        return [
            'active'   => (int) ($rows['not_started'] ?? 0) + (int) ($rows['in_progress'] ?? 0) + (int) ($rows['on_hold'] ?? 0),
            'finished' => (int) ($rows['finished'] ?? 0),
        ];
    }

    private function ticketSummary(Client $client): array
    {
        if (! Schema::hasTable('tickets')) {
            return ['open' => 0, 'closed' => 0];
        }

        $rows = DB::table('tickets')
            ->whereNull('deleted_at')
            ->where('tenant_id', $client->tenant_id)
            ->where('customer_id', $client->id)
            ->whereNull('merged_into_id')
            ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        return [
            'open'   => (int) ($rows['open'] ?? 0) + (int) ($rows['in-progress'] ?? 0),
            'closed' => (int) ($rows['closed'] ?? 0),
        ];
    }

    /**
     * Things waiting on the customer.
     *
     * Deliberately only what THEY can act on. An internal alert like "this
     * account is at risk" belongs on our side of the glass, not theirs.
     */
    private function openActions(ClientContact $contact, Client $client, array $fin): array
    {
        $out = [];

        if ($this->can($contact, 'invoice') && $fin['overdue'] > 0) {
            $out[] = [
                'key'     => 'overdue_invoices',
                'urgency' => 'high',
                'message' => $fin['overdue'].' '.($fin['overdue'] === 1 ? 'invoice is' : 'invoices are').' overdue',
                'link'    => '/portal/invoices?filter=overdue',
            ];
        }

        if ($this->can($contact, 'estimate') && Schema::hasTable('estimates')) {
            $awaiting = DB::table('estimates')
                ->whereNull('deleted_at')
                ->where('tenant_id', $client->tenant_id)
                ->where('client_id', $client->id)
                ->whereIn('status', ['Sent'])
                ->count();
            if ($awaiting > 0) {
                $out[] = [
                    'key'     => 'estimates_awaiting',
                    'urgency' => 'normal',
                    'message' => $awaiting.' '.($awaiting === 1 ? 'estimate is' : 'estimates are').' waiting for your response',
                    'link'    => '/portal/estimates',
                ];
            }
        }

        if ($this->can($contact, 'proposal') && Schema::hasTable('proposals')) {
            // rel_type/rel_id, not client_id — proposals is polymorphic.
            $open = DB::table('proposals')
                ->where('tenant_id', $client->tenant_id)
                ->where('rel_type', 'customer')->where('rel_id', $client->id)
                ->whereNull('deleted_at')
                ->whereIn('status', ['Sent', 'Open'])
                ->count();
            if ($open > 0) {
                $out[] = [
                    'key'     => 'proposals_open',
                    'urgency' => 'normal',
                    'message' => $open.' '.($open === 1 ? 'proposal is' : 'proposals are').' awaiting your decision',
                    'link'    => '/portal/proposals',
                ];
            }
        }

        return $out;
    }

    /** Only notes explicitly marked client-visible ever leave our side. */
    public function notes(Client $client): array
    {
        return DB::table('client_notes')
            ->where('tenant_id', $client->tenant_id)
            ->where('client_id', $client->id)
            ->where('visibility', 'client')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'content', 'type', 'created_at'])
            ->map(fn ($n) => [
                'id' => $n->id, 'content' => $n->content, 'type' => $n->type,
                'at' => Carbon::parse($n->created_at)->toIso8601String(),
            ])->all();
    }

    /**
     * Files the customer may see.
     *
     * Opt-in, not opt-out: an attachment is internal until somebody says
     * otherwise. The `confidential` flag is belt-and-braces on top, so a file
     * marked confidential is excluded even if customer_visible was set by
     * mistake — the two together mean a single slip cannot leak a document.
     */
    public function files(Client $client): array
    {
        return DB::table('client_attachments')
            ->where('tenant_id', $client->tenant_id)
            ->where('client_id', $client->id)
            ->where('customer_visible', true)
            ->where('confidential', false)
            ->orderByDesc('created_at')
            ->get(['id', 'file_name', 'mime_type', 'file_size', 'created_at'])
            ->map(fn ($f) => [
                'id' => $f->id, 'name' => $f->file_name, 'mime' => $f->mime_type,
                'size' => (int) $f->file_size, 'at' => Carbon::parse($f->created_at)->toIso8601String(),
            ])->all();
    }
}
