<?php

namespace App\Services\Helpdesk;

use App\Exceptions\BusinessException;
use App\Models\Helpdesk\Ticket;
use Illuminate\Support\Str;

/**
 * Phase 6 — a short AI-generated summary of a ticket, cached on the ticket so it
 * is not regenerated on every page load. Regenerated only on demand.
 *
 * LLM SEAM: generate() looks for an existing provider key in the environment.
 * None is configured in this project yet (checked ANTHROPIC/OPENAI/GEMINI/GROQ),
 * so it returns a clearly-labeled extractive placeholder instead of blocking the
 * feature. When a key is added, wire the real call inside generate() — the caching,
 * endpoint and UI are already in place.
 */
class TicketSummaryService
{
    public function summarize(int $ticketId, int $tenantId, bool $force = false): Ticket
    {
        $ticket = Ticket::forTenant($tenantId)->with('replies')->find($ticketId);
        if (! $ticket) {
            throw new BusinessException('Ticket not found.', 404);
        }

        // Cached unless a refresh is explicitly requested.
        if ($ticket->ai_summary && ! $force) {
            return $ticket;
        }

        $ticket->update([
            'ai_summary'    => $this->generate($ticket),
            'ai_summary_at' => now(),
        ]);

        return $ticket->fresh();
    }

    /** True when a real LLM provider key is configured (none yet in this project). */
    public function hasProvider(): bool
    {
        return (bool) ($this->providerKey());
    }

    private function providerKey(): ?string
    {
        foreach (['ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'GEMINI_API_KEY', 'GROQ_API_KEY'] as $var) {
            if ($key = env($var)) {
                return $key;
            }
        }

        return null;
    }

    private function generate(Ticket $ticket): string
    {
        $transcript = $this->buildTranscript($ticket);

        // When a provider key exists, call it here and return its summary.
        // Until then, a labeled extractive placeholder keeps the feature usable.
        if ($this->providerKey()) {
            // return $this->callProvider($ticket->subject, $transcript);
        }

        return $this->placeholder($ticket, $transcript);
    }

    private function buildTranscript(Ticket $ticket): string
    {
        $lines = [strip_tags((string) $ticket->description)];
        foreach ($ticket->replies as $r) {
            $lines[] = ucfirst($r->sender_type).': '.strip_tags((string) $r->message);
        }

        return trim(implode("\n", array_filter($lines)));
    }

    private function placeholder(Ticket $ticket, string $transcript): string
    {
        // The customer's opening description counts as the first message, plus
        // every reply since — so a brand-new ticket reads "1 message", not "0".
        $messageCount = ($ticket->description ? 1 : 0) + $ticket->replies->count();
        $extract = Str::limit(preg_replace('/\s+/', ' ', $transcript), 220);

        return '⚠️ AI summary placeholder — no LLM API key is configured yet, so this '
            ."is an automatic extract, not a real AI summary. Ticket \"{$ticket->subject}\" "
            ."has {$messageCount} message(s). Opening details: {$extract}";
    }
}
