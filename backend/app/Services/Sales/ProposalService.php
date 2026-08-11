<?php

namespace App\Services\Sales;

use App\Exceptions\BusinessException;
use App\Exceptions\UnauthorizedTenantException;
use App\Models\Sales\Estimate;
use App\Models\Sales\Proposal;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesLineItem;
use App\Models\Customer\Client;
use App\Models\Customer\ClientContact;
use App\Models\Sales\Lead;
use App\Repositories\Sales\ProposalRepository;
use App\Services\Mail\TenantMailer;
use App\Support\HtmlSanitizer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProposalService
{
    public function __construct(
        private ProposalRepository $proposalRepository,
        private ContentPageService $contentPages,
    ) {
    }

    public function list(int $tenantId, ?string $status, ?string $search)
    {
        return $this->proposalRepository->filtered($tenantId, $status, $search);
    }

    public function create(array $data, array $lineItems, int $tenantId, int $userId): Proposal
    {
        return DB::transaction(function () use ($data, $lineItems, $tenantId, $userId) {
            $pages = $data['pages'] ?? null;
            unset($data['pages']);
            // Rich text (notepad editor) — sanitized before it ever reaches the DB.
            $data = HtmlSanitizer::cleanFields($data, ['terms', 'notes']);
            if (array_key_exists('cover', $data)) {
                $data['cover'] = $this->cleanCover($data['cover']);
            }
            $this->assertContactBelongs($data, $tenantId);

            $proposal = Proposal::create([
                ...$data,
                'tenant_id'  => $tenantId,
                'created_by' => $userId,
                'status'     => $data['status'] ?? 'Draft',
            ]);

            if (is_array($pages)) {
                $this->contentPages->syncPages($proposal, $pages, $tenantId);
            }

            $this->syncLineItems($proposal, $lineItems);
            $proposal->recalcTotals();
            $proposal->logActivity('created', "Proposal \"{$proposal->subject}\" created", null, null, $userId);

            Log::channel('sales')->info('Proposal created', ['proposal_id' => $proposal->id, 'tenant_id' => $tenantId]);

            return $proposal->load('lineItems');
        });
    }

    public function show(Proposal $proposal, int $tenantId): Proposal
    {
        $this->assertTenant($proposal, $tenantId);
        return $proposal->load([
            'lineItems', 'assignedUser', 'pages',
            'contact:id,client_id,first_name,last_name,email,phone',
            'activities.performer:id,name',
        ]);
    }

    public function update(Proposal $proposal, array $data, ?array $lineItems, bool $hasLineItems, int $tenantId): Proposal
    {
        $this->assertTenant($proposal, $tenantId);

        return DB::transaction(function () use ($proposal, $data, $lineItems, $hasLineItems, $tenantId) {
            $pages = $data['pages'] ?? null;
            $hasPages = array_key_exists('pages', $data);
            unset($data['pages']);
            // Rich text (notepad editor) — sanitized before it ever reaches the DB.
            $data = HtmlSanitizer::cleanFields($data, ['terms', 'notes']);
            if (array_key_exists('cover', $data)) {
                $data['cover'] = $this->cleanCover($data['cover']);
            }
            $this->assertContactBelongs([
                'rel_type' => $data['rel_type'] ?? $proposal->rel_type,
                'rel_id' => $data['rel_id'] ?? $proposal->rel_id,
                'contact_id' => $data['contact_id'] ?? null,
            ], $tenantId);

            $proposal->update($data);

            if ($hasPages) {
                $this->contentPages->syncPages($proposal, $pages ?? [], $tenantId);
            }

            if ($hasLineItems) {
                $this->syncLineItems($proposal, $lineItems ?? []);
                $proposal->recalcTotals();
            }

            Log::channel('sales')->info('Proposal updated', ['proposal_id' => $proposal->id, 'tenant_id' => $tenantId]);

            return $proposal->fresh()->load('lineItems');
        });
    }

    public function delete(Proposal $proposal, int $tenantId): void
    {
        $this->assertTenant($proposal, $tenantId);
        $proposal->delete();
        Log::channel('sales')->info('Proposal deleted', ['proposal_id' => $proposal->id, 'tenant_id' => $tenantId]);
    }

    public function send(Proposal $proposal, int $tenantId): Proposal
    {
        $this->assertTenant($proposal, $tenantId);
        $proposal->update(['status' => 'Sent', 'sent_at' => now()]);
        Log::channel('sales')->info('Proposal sent', ['proposal_id' => $proposal->id, 'tenant_id' => $tenantId]);
        return $proposal->fresh();
    }

    public function updateStatus(Proposal $proposal, string $status, int $tenantId, ?string $rejectionReason = null): Proposal
    {
        $this->assertTenant($proposal, $tenantId);

        $data = ['status' => $status];
        if ($status === 'Accepted') $data['accepted_at'] = now();
        if ($status === 'Declined') $data['declined_at'] = now();

        $proposal->update($data);

        if ($status === 'Accepted') {
            $this->convertLeadToCustomerOnAcceptance($proposal);
        }

        if ($status === 'Declined') {
            if ($rejectionReason) {
                $proposal->update(['tags' => trim(($proposal->tags ? $proposal->tags . ', ' : '') . 'Reason: ' . $rejectionReason)]);
                $proposal->logActivity('declined', "Proposal declined with reason: {$rejectionReason}");
            }
            if ($proposal->rel_type === 'lead' && $proposal->rel_id) {
                $lead = Lead::where('tenant_id', $tenantId)->find($proposal->rel_id);
                if ($lead && ! $lead->is_converted) {
                    $lostStatus = \App\Models\Sales\LeadStatus::where('tenant_id', $tenantId)->where('name', 'like', '%Lost%')->first();
                    if ($lostStatus) {
                        $lead->update(['status_id' => $lostStatus->id]);
                    }
                }
            }
        }

        Log::channel('sales')->info('Proposal status updated', ['proposal_id' => $proposal->id, 'status' => $status, 'tenant_id' => $tenantId]);

        return $proposal->fresh();
    }

    private function convertLeadToCustomerOnAcceptance(Proposal $proposal): void
    {
        if ($proposal->rel_type !== 'lead' || empty($proposal->rel_id)) {
            return;
        }

        $lead = Lead::where('tenant_id', $proposal->tenant_id)->find($proposal->rel_id);
        if (! $lead || $lead->is_converted) {
            return;
        }

        // Create Client record for tenant
        $companyName = $lead->company ?: $lead->name ?: 'New Customer';
        $client = Client::create([
            'tenant_id'      => $proposal->tenant_id,
            'company'        => $companyName,
            'gst_number'     => $lead->vat ?? $lead->gst_number ?? null,
            'phone'          => $lead->phone ?? null,
            'website'        => $lead->website ?? null,
            'address'        => $lead->address ?? null,
            'city'           => $lead->city ?? null,
            'state'          => $lead->state ?? null,
            'zip'            => $lead->zip ?? null,
            'country'        => $lead->country ?? 'India',
            'added_by'       => $proposal->created_by ?? null,
        ]);

        // Create primary ClientContact
        $name = trim($lead->name ?: 'Main Contact');
        $spacePos = strrpos($name, ' ');
        $firstName = $spacePos !== false ? substr($name, 0, $spacePos) : $name;
        $lastName = $spacePos !== false ? substr($name, $spacePos + 1) : 'Contact';

        $contact = ClientContact::create([
            'tenant_id'  => $proposal->tenant_id,
            'client_id'  => $client->id,
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'email'      => $lead->email ?: null,
            'phone'      => $lead->phone ?: null,
            'title'      => $lead->title ?: 'Primary Contact',
            'is_primary' => true,
        ]);

        // Update Lead state
        $lead->update([
            'is_converted' => true,
            'converted_at' => now(),
            'client_id'    => $client->id,
        ]);

        // Automatically update proposal reference to new customer
        $proposal->update([
            'rel_type'   => 'customer',
            'rel_id'     => $client->id,
            'contact_id' => $contact->id,
        ]);

        $proposal->logActivity('lead_converted', "Lead '{$lead->name}' automatically converted to Customer '{$client->company}' upon proposal acceptance.");
    }

    /**
     * Store the public-view URL as the QR payload. The frontend renders
     * the actual QR code image client-side (qrcode.react) from this
     * string — no server-side image generation needed.
     */
    public function generateQR(Proposal $proposal, string $baseUrl, int $tenantId): Proposal
    {
        $this->assertTenant($proposal, $tenantId);

        $proposal->update([
            'qr_code_data' => rtrim($baseUrl, '/') . '/portal/proposals/' . $proposal->portal_token,
        ]);

        Log::channel('sales')->info('Proposal QR generated', ['proposal_id' => $proposal->id, 'tenant_id' => $tenantId]);

        return $proposal->fresh();
    }

    /**
     * Public, unauthenticated lookup by portal_token — used by the
     * client-facing proposal view and by trackEmailOpen(). OTP
     * verification (public_view_otp_enabled) is stored but not enforced
     * here: there is no OTP delivery mechanism in this codebase yet
     * (Master Plan V2 explicitly defers "Proposal email OTP
     * verification" to a future phase), so enforcing it now would just
     * lock every OTP-enabled proposal out with no way to complete the
     * flow.
     */
    public function findByPortalToken(string $token): Proposal
    {
        $proposal = Proposal::where('portal_token', $token)->first();

        if (! $proposal) {
            throw new \App\Exceptions\ResourceNotFoundException('Proposal');
        }

        return $proposal->load('lineItems');
    }

    /**
     * Whitelisted payload for the public portal — never leaks internal
     * fields (creator, tenant internals, email engagement, tokens).
     */
    public function publicPayload(Proposal $proposal): array
    {
        $proposal->loadMissing(['lineItems', 'pages']);

        return [
            'subject'      => $proposal->subject,
            'reference_no' => $proposal->reference_no,
            'date'         => $proposal->date?->toDateString(),
            'open_till'    => $proposal->open_till?->toDateString(),
            'currency'     => $proposal->currency,
            'proposal_to'  => $proposal->proposal_to,
            'address'      => $proposal->address,
            'city'         => $proposal->city,
            'state'        => $proposal->state,
            'country'      => $proposal->country,
            'zip'          => $proposal->zip,
            'status'       => $proposal->status,
            'notes'        => $proposal->notes,
            'terms'        => $proposal->terms,
            'subtotal'     => $proposal->subtotal,
            'tax_total'    => $proposal->tax_total,
            'tax_breakdown'=> $proposal->taxBreakdown(),
            'total'        => $proposal->total,
            'supply_type'  => $proposal->supply_type,
            'discount_amount' => $proposal->discount_amount,
            'accepted_at'  => $proposal->accepted_at,
            'declined_at'  => $proposal->declined_at,
            'is_expired'   => (bool) ($proposal->open_till && $proposal->open_till->isPast() && ! in_array($proposal->status, ['Accepted', 'Declined'])),
            'cover'        => $proposal->cover,
            'pages'        => $proposal->pages->map(fn ($pg) => ['title' => $pg->title, 'content' => $pg->content])->values(),
            'line_items'   => $proposal->lineItems->map(fn ($li) => [
                'item_name' => $li->item_name, 'description' => $li->description,
                'qty' => $li->qty, 'unit' => $li->unit, 'rate' => $li->rate,
                'tax' => $li->tax, 'discount' => $li->discount, 'amount' => $li->total,
            ])->values(),
        ];
    }

    /** Teaser shown while the OTP gate is locked — enough to know what it is, nothing more. */
    public function teaserPayload(Proposal $proposal): array
    {
        return ['subject' => $proposal->subject, 'reference_no' => $proposal->reference_no];
    }

    public function recordPortalView(Proposal $proposal): void
    {
        $first = (int) $proposal->portal_view_count === 0;
        $proposal->forceFill(['portal_viewed_at' => now()])->save();
        $proposal->increment('portal_view_count');

        // Log the first portal view to the pipeline timeline (the counter on
        // the engagement panel tracks the rest, so we don't flood it).
        if ($first) {
            $proposal->logActivity('portal_viewed', 'Client viewed the proposal on the secure link');
        }
    }

    public function publicRespond(Proposal $proposal, string $action, ?string $ip = null, ?string $userAgent = null): Proposal
    {
        return DB::transaction(function () use ($proposal, $action, $ip, $userAgent) {
            $locked = Proposal::whereKey($proposal->id)->lockForUpdate()->first();
            if ($locked->status !== 'Sent') {
                throw new BusinessException('This proposal can no longer be responded to.', 409);
            }
            // Old-CRM rule: past open_till the public link is view-only.
            if ($locked->open_till && $locked->open_till->isPast()) {
                throw new BusinessException('This proposal has expired and can no longer be responded to.', 409);
            }
            $locked->update($action === 'accept'
                ? [
                    'status' => 'Accepted', 'accepted_at' => now(),
                    'acceptance_ip' => $ip,
                    'acceptance_user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
                ]
                : ['status' => 'Declined', 'declined_at' => now()]);

            $locked->logActivity(
                $action === 'accept' ? 'accepted' : 'declined',
                $action === 'accept' ? 'Client accepted the proposal' : 'Client declined the proposal',
                null, $action === 'accept' ? "IP {$ip}" : null,
            );

            if ($action === 'accept') {
                $this->convertLeadToCustomerOnAcceptance($locked);
            }

            Log::channel('sales')->info("Proposal {$action}ed via portal", [
                'proposal_id' => $locked->id, 'ip' => $ip,
            ]);

            return $locked;
        });
    }

    /** Shared PDF renderer (detail download + submit email attachment). */
    public function renderPdf(Proposal $proposal): string
    {
        $proposal->loadMissing(['lineItems', 'pages']);

        return Pdf::loadView('pdf.proposal', ['proposal' => $proposal])->output();
    }

    /**
     * B-4 submit: persist the email draft first, send via the tenant's
     * mailer with PDF + portal link, and only mark Sent after a successful
     * send — a failure leaves the proposal Draft with the draft retained.
     */
    public function submit(Proposal $proposal, array $email, int $tenantId, TenantMailer $mailer, string $frontendUrl, string $apiUrl): Proposal
    {
        $this->assertTenant($proposal, $tenantId);
        $proposal->loadMissing('contact');

        $to = $proposal->contact?->email ?: $proposal->email;
        if (! $to) {
            throw new BusinessException('Assign a recipient contact with an email address before submitting.', 422);
        }

        $cc = array_slice(array_values(array_unique($email['cc'] ?? [])), 0, 10);
        $subject = $email['subject'] ?? "Proposal: {$proposal->subject}";
        $body = HtmlSanitizer::clean($email['body'] ?? '');

        // Draft survives a failed send.
        $proposal->update(['email_subject' => $subject, 'email_body' => $body, 'email_cc' => $cc]);

        $portalUrl = rtrim($frontendUrl, '/').'/portal/proposals/'.$proposal->portal_token;
        $pixelUrl  = rtrim($apiUrl, '/').'/api/public/proposals/'.$proposal->portal_token.'/track';
        $pdf = $this->renderPdf($proposal);

        // Decode any user-added attachments (data-URL or bare base64) → raw bytes.
        $attachments = collect($email['attachments'] ?? [])
            ->map(function ($a) {
                $raw = (string) ($a['data'] ?? '');
                if (str_contains($raw, ',')) {
                    $raw = substr($raw, strpos($raw, ',') + 1); // strip "data:...;base64," prefix
                }
                $content = base64_decode($raw, true);
                return $content === false || $content === ''
                    ? null
                    : ['name' => $a['name'] ?? 'attachment', 'mime' => $a['mime'] ?? 'application/octet-stream', 'content' => $content];
            })
            ->filter()->values()->all();

        $mailer->send($tenantId, $to, new ProposalMail($proposal, $body, $portalUrl, $pixelUrl, $pdf, $subject, $attachments), $cc);

        $resend = (bool) $proposal->sent_at;
        $proposal->update([
            'status'          => in_array($proposal->status, ['Accepted', 'Declined']) ? $proposal->status : 'Sent',
            'sent_at'         => $proposal->sent_at ?? now(),
            'last_emailed_at' => now(),
        ]);

        $proposal->logActivity(
            $resend ? 'resent' : 'sent',
            ($resend ? 'Proposal re-emailed to ' : 'Proposal emailed to ').$to.(count($cc) ? ' (cc '.count($cc).')' : ''),
        );

        Log::channel('sales')->info('Proposal emailed', ['proposal_id' => $proposal->id, 'tenant_id' => $tenantId, 'cc_count' => count($cc)]);

        return $proposal->fresh()->load(['lineItems', 'pages', 'contact']);
    }

    public function trackEmailOpen(string $token, ?string $device): void
    {
        $proposal = Proposal::where('portal_token', $token)->first();
        if (! $proposal) return;

        $first = (int) $proposal->email_opened_count === 0;
        $proposal->increment('email_opened_count');
        $proposal->update([
            'email_opened_at'     => $proposal->email_opened_at ?? now(),
            'email_opened_device' => $device ?? $proposal->email_opened_device,
        ]);

        if ($first) {
            $proposal->logActivity('email_opened', 'Client opened the proposal email');
        }

        Log::channel('sales')->info('Proposal email opened', [
            'proposal_id' => $proposal->id, 'tenant_id' => $proposal->tenant_id, 'count' => $proposal->email_opened_count,
        ]);
    }

    /**
     * Convert a proposal into a Proforma Invoice (estimate). Only valid for a
     * customer-linked proposal — a lead-linked proposal must be converted to a
     * customer first (mirrors the old CRM: leads have no client_id to bill).
     */
    public function convertToEstimate(Proposal $proposal, int $tenantId, int $userId): Estimate
    {
        $this->assertTenant($proposal, $tenantId);
        $clientId = $this->requireCustomer($proposal);

        return DB::transaction(function () use ($proposal, $clientId, $tenantId, $userId) {
            $estimate = Estimate::create([
                'tenant_id'     => $tenantId,
                'subject'       => $proposal->subject,
                'client_id'     => $clientId,
                'project_id'    => $proposal->project_id,
                'date'          => now()->toDateString(),
                'currency'      => $proposal->currency,
                'discount_type' => $proposal->discount_type,
                'status'        => 'Draft',
                'terms'         => $proposal->terms,
                'created_by'    => $userId,
            ]);

            $this->copyLineItems($proposal, $estimate, Estimate::class);
            $estimate->recalcTotals();

            $proposal->update([
                'status'                => 'Accepted',
                'converted_estimate_id' => $estimate->id,
                'accepted_at'           => $proposal->accepted_at ?? now(),
            ]);

            Log::channel('sales')->info('Proposal converted to estimate', [
                'proposal_id' => $proposal->id, 'estimate_id' => $estimate->id, 'tenant_id' => $tenantId,
            ]);

            return $estimate->fresh()->load('lineItems');
        });
    }

    /**
     * Convert a proposal directly into a Tax Invoice. Customer-linked only.
     */
    public function convertToInvoice(Proposal $proposal, ?string $dueDate, int $tenantId, int $userId): SalesInvoice
    {
        $this->assertTenant($proposal, $tenantId);
        $clientId = $this->requireCustomer($proposal);

        return DB::transaction(function () use ($proposal, $clientId, $dueDate, $tenantId, $userId) {
            $invoice = SalesInvoice::create([
                'tenant_id'     => $tenantId,
                'client_id'     => $clientId,
                'project_id'    => $proposal->project_id,
                'date'          => now()->toDateString(),
                'due_date'      => $dueDate ?? now()->addDays(30)->toDateString(),
                'currency'      => $proposal->currency,
                'discount_type' => $proposal->discount_type,
                'status'        => 'Draft',
                'terms'         => $proposal->terms,
                // Carry the proposal owner as sale agent so commission has an
                // attributee when the invoice is later paid.
                'sale_agent'    => $proposal->assigned_to,
                'created_by'    => $userId,
            ]);

            $this->copyLineItems($proposal, $invoice, SalesInvoice::class);
            $invoice->recalcTotals();
            $invoice->update(['balance' => $invoice->total]);

            $proposal->update([
                'status'               => 'Accepted',
                'converted_invoice_id' => $invoice->id,
                'accepted_at'          => $proposal->accepted_at ?? now(),
            ]);

            Log::channel('sales')->info('Proposal converted to invoice', [
                'proposal_id' => $proposal->id, 'invoice_id' => $invoice->id, 'tenant_id' => $tenantId,
            ]);

            return $invoice->fresh();
        });
    }

    private function requireCustomer(Proposal $proposal): int
    {
        if ($proposal->rel_type !== 'customer' || empty($proposal->rel_id)) {
            throw new BusinessException('Convert the lead to a customer before converting this proposal.', 422);
        }

        return (int) $proposal->rel_id;
    }

    private function copyLineItems(Proposal $proposal, $target, string $targetClass): void
    {
        foreach ($proposal->lineItems as $idx => $li) {
            SalesLineItem::create([
                'lineable_type' => $targetClass,
                'lineable_id'   => $target->id,
                'item_id'       => $li->item_id,
                'item_name'     => $li->item_name,
                'description'   => $li->description,
                'qty'           => $li->qty,
                'unit'          => $li->unit,
                'rate'          => $li->rate,
                'tax'           => $li->tax,
                'discount'      => $li->discount,
                'total'         => $li->total,
                'sort_order'    => $idx,
            ]);
        }
    }

    private function assertTenant(Proposal $proposal, int $tenantId): void
    {
        if ($proposal->tenant_id !== $tenantId) {
            Log::channel('sales')->warning('Unauthorized proposal access attempt', ['proposal_id' => $proposal->id, 'tenant_id' => $tenantId]);
            throw new UnauthorizedTenantException('Unauthorized');
        }
    }

    private function syncLineItems(Proposal $proposal, array $items): void
    {
        SalesLineItem::where('lineable_type', Proposal::class)
                     ->where('lineable_id', $proposal->id)
                     ->delete();

        foreach ($items as $idx => $item) {
            $taxInfo = SalesLineItem::normalizeTaxes($item);
            $item['tax'] = $taxInfo['tax'];
            // Resolve % discounts to an amount for the stored line total.
            $item['discount'] = SalesLineItem::discountAmount($item);
            $total = SalesLineItem::computeTotal($item);
            SalesLineItem::create([
                'lineable_type' => Proposal::class,
                'lineable_id'   => $proposal->id,
                'item_id'       => $item['item_id'] ?? null,
                'item_name'     => $item['item_name'],
                'description'   => $item['description'] ?? null,
                'hsn_sac_code'  => $item['hsn_sac_code'] ?? null,
                'qty'           => $item['qty'],
                'unit'          => $item['unit'] ?? 'pcs',
                'rate'          => $item['rate'],
                'tax'           => $taxInfo['tax'],
                'taxes'         => $taxInfo['taxes'],
                'discount'      => $item['discount'],
                'discount_mode' => $item['discount_mode'] ?? 'fixed',
                'total'         => $total,
                'sort_order'    => $idx,
            ]);
        }
    }


    /**
     * Clean the cover-page payload: image restricted to a data:image or https
     * URL, title/heading reduced to plain text (they render as headings, not
     * HTML). Returns null when the cover is absent or disabled with no content.
     */
    private function cleanCover($cover): ?array
    {
        return \App\Support\CoverSanitizer::clean($cover);
    }

    /** A chosen recipient contact must belong to the proposal's customer (and tenant). */
    private function assertContactBelongs(array $data, int $tenantId): void
    {
        $contactId = $data['contact_id'] ?? null;
        if (! $contactId) {
            return;
        }
        if (($data['rel_type'] ?? null) !== 'customer' || empty($data['rel_id'])) {
            throw new BusinessException('A recipient contact requires a customer.', 422);
        }
        $ok = ClientContact::where('id', $contactId)
            ->where('tenant_id', $tenantId)
            ->where('client_id', $data['rel_id'])
            ->exists();
        if (! $ok) {
            throw new BusinessException('Selected contact does not belong to this customer.', 422);
        }
    }
}
