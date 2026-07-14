<?php

namespace App\Services\Helpdesk;

use App\Exceptions\BusinessException;
use App\Models\Helpdesk\Ticket;
use App\Models\Helpdesk\TicketReply;

/**
 * Turns an inbound email (from a provider route/webhook or an IMAP poller) into
 * a customer reply on the right ticket. This is the inbound half of the
 * "works like email, back and forth" flow, and the mechanism that lets a client
 * REOPEN a closed ticket simply by replying to its email.
 *
 * Trust model: the ticket is identified by the plus-addressed recipient
 * support+{id}-{token}@domain. The token is the per-ticket secret, so knowing a
 * ticket id is not enough to inject a message — the reply must come back to the
 * exact threaded address we sent from.
 */
class InboundEmailService
{
    public function __construct(private HelpdeskService $helpdesk)
    {
    }

    /**
     * @param  array  $payload  normalized inbound email:
     *   to|recipient : the threaded address (support+{id}-{token}@domain)
     *   from         : sender address (recorded, not trusted for auth)
     *   subject      : used only as a fallback to find the ticket id
     *   text|body    : the reply body (plain text preferred)
     */
    public function ingest(array $payload): TicketReply
    {
        [$ticketId, $token] = $this->extractRef(
            $payload['to'] ?? $payload['recipient'] ?? '',
            $payload['subject'] ?? ''
        );

        if (! $ticketId) {
            throw new BusinessException('Could not determine the ticket from the recipient address.', 422);
        }

        $ticket = Ticket::find($ticketId);
        if (! $ticket) {
            throw new BusinessException('Ticket not found.', 404);
        }

        // Token must match the one we threaded into the outbound Reply-To.
        if (! $token || ! hash_equals((string) $ticket->email_token, $token)) {
            throw new BusinessException('Invalid or missing ticket token.', 403);
        }

        $body = $this->cleanBody((string) ($payload['text'] ?? $payload['body'] ?? ''));
        if ($body === '') {
            throw new BusinessException('Empty email body.', 422);
        }

        // Append as a CLIENT reply — HelpdeskService::addReply already reopens a
        // closed ticket on a client reply and leaves the SLA/first-response
        // accounting untouched for inbound customer messages.
        return $this->helpdesk->addReply($ticket->id, [
            'sender_type' => 'client',
            'sender_id'   => $ticket->customer_id,
            'message'     => $body,
            'cc'          => [],
            'attachments' => [],
        ], $ticket->tenant_id);
    }

    /** Pull {id, token} from the threaded address, falling back to a subject "#id". */
    private function extractRef(string $recipient, string $subject): array
    {
        if (preg_match('/\+(\d+)-([A-Za-z0-9]+)@/', $recipient, $m)) {
            return [(int) $m[1], $m[2]];
        }

        // Fallback: a bare "#123" in the subject locates the ticket, but without a
        // token the caller still fails the token check below — so this only helps
        // surface a clearer error, never bypasses auth.
        if (preg_match('/#(\d+)/', $subject, $m)) {
            return [(int) $m[1], null];
        }

        return [null, null];
    }

    /**
     * Strip the quoted history most mail clients append below a reply, so the
     * ticket thread shows only what the customer actually wrote.
     */
    private function cleanBody(string $text): string
    {
        $text = str_replace("\r\n", "\n", $text);

        $cutMarkers = [
            '/^\s*On .+ wrote:\s*$/mi',              // "On Mon, ... wrote:"
            '/^\s*-{2,}\s*Original Message\s*-{2,}/mi',
            '/^\s*_{5,}\s*$/m',                       // long underscore separators
            '/^\s*From:\s.+$/mi',                     // forwarded header block
        ];
        foreach ($cutMarkers as $re) {
            if (preg_match($re, $text, $m, PREG_OFFSET_CAPTURE)) {
                $text = substr($text, 0, $m[0][1]);
            }
        }

        // Drop trailing quoted ">" lines.
        $lines = array_filter(explode("\n", $text), fn ($l) => ! preg_match('/^\s*>/', $l));

        return trim(implode("\n", $lines));
    }
}
