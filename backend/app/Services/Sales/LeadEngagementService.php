<?php

namespace App\Services\Sales;

use App\Exceptions\BusinessException;
use App\Exceptions\UnauthorizedTenantException;
use App\Mail\Sales\LeadEmail as LeadEmailMailable;
use App\Models\Sales\Lead;
use App\Models\Sales\LeadAttachment;
use App\Models\Sales\LeadEmail;
use App\Services\Mail\TenantMailer;
use App\Support\HtmlSanitizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Attachments and email activity for a lead.
 *
 * The old CRM's Email Activity tab only ever showed INBOUND mail captured by an
 * IMAP cron. We have no inbound infrastructure, so rather than ship an empty tab
 * this records what we can honestly observe — mail the CRM sends to the lead —
 * and stores a `direction` so inbound slots in later without a reshape.
 */
class LeadEngagementService
{
    /** Mirrors ClientAttachmentController's allow-list: no active-content types. */
    public const ATTACHMENT_MIMES = 'pdf,doc,docx,xls,xlsx,csv,ppt,pptx,txt,png,jpg,jpeg,gif,webp,zip';

    /* ── Attachments ─────────────────────────────────────────── */

    public function attachments(Lead $lead, int $tenantId)
    {
        $this->assertTenant($lead, $tenantId);

        return LeadAttachment::forTenant($tenantId)
            ->where('lead_id', $lead->id)
            ->with('uploader:id,name')
            ->latest()
            ->get();
    }

    public function addAttachment(Lead $lead, UploadedFile $file, int $tenantId, int $userId): LeadAttachment
    {
        $this->assertTenant($lead, $tenantId);

        $path = $file->store("lead-attachments/{$tenantId}/{$lead->id}", 'public');

        $attachment = LeadAttachment::create([
            'tenant_id'  => $tenantId,
            'lead_id'    => $lead->id,
            'file_name'  => $this->safeName($file->getClientOriginalName()),
            'file_path'  => $path,
            'mime_type'  => $file->getClientMimeType(),
            'file_size'  => $file->getSize(),
            'created_by' => $userId,
        ]);

        $lead->logActivity('attachment_added', "File \"{$attachment->file_name}\" attached");

        return $attachment->load('uploader:id,name');
    }

    public function deleteAttachment(Lead $lead, LeadAttachment $attachment, int $tenantId): void
    {
        $this->assertTenant($lead, $tenantId);

        if ((int) $attachment->lead_id !== (int) $lead->id) {
            abort(404);
        }

        Storage::disk('public')->delete($attachment->file_path);
        $name = $attachment->file_name;
        $attachment->delete();

        $lead->logActivity('attachment_removed', "File \"{$name}\" removed");
    }

    /** Strip path separators and HTML-unsafe characters from the display name. */
    private function safeName(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[^\w.\- ]+/u', '_', $name) ?? 'file';

        return mb_substr(trim($name), 0, 255) ?: 'file';
    }

    /* ── Email activity ──────────────────────────────────────── */

    public function emails(Lead $lead, int $tenantId)
    {
        $this->assertTenant($lead, $tenantId);

        return LeadEmail::forTenant($tenantId)
            ->where('lead_id', $lead->id)
            ->with('sender:id,name')
            ->latest()
            ->get();
    }

    /**
     * Send an email to the lead and log it.
     *
     * The log row is written whatever happens: a send that failed is exactly the
     * thing someone needs to see, and silently dropping it would leave the tab
     * implying the mail went out.
     */
    public function sendEmail(Lead $lead, array $data, int $tenantId, int $userId, TenantMailer $mailer): LeadEmail
    {
        $this->assertTenant($lead, $tenantId);

        $to = trim((string) ($data['to_email'] ?? $lead->email ?? ''));
        if ($to === '') {
            throw new BusinessException('This lead has no email address — add one first.');
        }

        $subject = trim((string) $data['subject']);
        $body    = HtmlSanitizer::clean((string) ($data['body'] ?? ''));

        $log = LeadEmail::create([
            'tenant_id'  => $tenantId,
            'lead_id'    => $lead->id,
            'direction'  => 'outbound',
            'to_email'   => $to,
            'subject'    => $subject,
            'body'       => $body,
            'status'     => 'sent',
            'sent_at'    => now(),
            'created_by' => $userId,
        ]);

        try {
            $mailer->send($tenantId, $to, new LeadEmailMailable($body, (string) $lead->name, $subject));
        } catch (\Throwable $e) {
            $log->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000), 'sent_at' => null]);
            Log::channel('sales')->warning('Lead email failed', [
                'lead_id' => $lead->id, 'tenant_id' => $tenantId, 'error' => $e->getMessage(),
            ]);

            throw new BusinessException(
                'The email could not be sent. Check Settings → Email, then try again — a record of the attempt has been kept.'
            );
        }

        // Sending is contact, so the lead's last-contact date should reflect it.
        $lead->update(['last_contact_date' => now()]);
        $lead->logActivity('email_sent', "Email sent to {$to}: {$subject}");

        return $log->load('sender:id,name');
    }

    private function assertTenant(Lead $lead, int $tenantId): void
    {
        if ((int) $lead->tenant_id !== $tenantId) {
            throw new UnauthorizedTenantException();
        }
    }
}
