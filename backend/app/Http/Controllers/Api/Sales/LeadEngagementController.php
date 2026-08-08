<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\Sales\Lead;
use App\Models\Sales\LeadAttachment;
use App\Services\Customer\CustomFieldService;
use App\Services\Mail\TenantMailer;
use App\Services\Sales\LeadEngagementService;
use Illuminate\Http\Request;

/**
 * The lead profile's engagement tabs: attachments, email activity and custom
 * fields.
 *
 * Tenant id always comes from the authenticated user; the service re-asserts that
 * the lead belongs to that tenant before any read or write.
 */
class LeadEngagementController extends Controller
{
    public function __construct(
        private LeadEngagementService $engagement,
        private CustomFieldService $customFields,
    ) {
    }

    /* ── Attachments ─────────────────────────────────────────── */

    public function attachments(Request $request, Lead $lead)
    {
        return response()->json($this->engagement->attachments($lead, $request->user()->tenant_id));
    }

    public function storeAttachment(Request $request, Lead $lead)
    {
        $request->validate([
            'file' => 'required|file|max:20480|mimes:' . LeadEngagementService::ATTACHMENT_MIMES,
        ]);

        return response()->json($this->engagement->addAttachment(
            $lead,
            $request->file('file'),
            $request->user()->tenant_id,
            $request->user()->id,
        ), 201);
    }

    public function destroyAttachment(Request $request, Lead $lead, LeadAttachment $attachment)
    {
        $this->engagement->deleteAttachment($lead, $attachment, $request->user()->tenant_id);

        return response()->json(['message' => 'Attachment deleted']);
    }

    /* ── Email activity ──────────────────────────────────────── */

    public function emails(Request $request, Lead $lead)
    {
        return response()->json($this->engagement->emails($lead, $request->user()->tenant_id));
    }

    public function sendEmail(Request $request, Lead $lead, TenantMailer $mailer)
    {
        $data = $request->validate([
            'to_email' => 'nullable|email',
            'subject'  => 'required|string|max:255',
            'body'     => 'required|string',
        ]);

        return response()->json($this->engagement->sendEmail(
            $lead,
            $data,
            $request->user()->tenant_id,
            $request->user()->id,
            $mailer,
        ), 201);
    }

    /* ── Custom fields ───────────────────────────────────────── */

    /**
     * Definitions merged with this lead's values, so the tab can render inputs
     * even for fields the lead has no value for yet. Reuses the same generic
     * engine the customer profile uses — only `field_to` differs.
     */
    public function customFields(Request $request, Lead $lead)
    {
        abort_if((int) $lead->tenant_id !== (int) $request->user()->tenant_id, 403);

        return response()->json($this->customFields->valuesFor(
            $request->user()->tenant_id,
            'leads',
            $lead->id,
            true,
        ));
    }

    public function saveCustomFields(Request $request, Lead $lead)
    {
        abort_if((int) $lead->tenant_id !== (int) $request->user()->tenant_id, 403);

        $data = $request->validate(['values' => 'required|array']);

        $this->customFields->saveValues($request->user()->tenant_id, 'leads', $lead->id, $data['values']);

        return response()->json($this->customFields->valuesFor(
            $request->user()->tenant_id,
            'leads',
            $lead->id,
            true,
        ));
    }
}
