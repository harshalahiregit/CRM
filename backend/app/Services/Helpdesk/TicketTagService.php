<?php

namespace App\Services\Helpdesk;

use App\Exceptions\BusinessException;
use App\Models\Helpdesk\Ticket;
use App\Models\Helpdesk\TicketTag;

/**
 * Phase 3 — free-form colored tags on tickets. Tags are tenant-wide; the pivot
 * links them to tickets.
 */
class TicketTagService
{
    public function listTags(int $tenantId)
    {
        return TicketTag::forTenant($tenantId)->orderBy('name')->get();
    }

    public function createTag(array $data, int $tenantId): TicketTag
    {
        return TicketTag::firstOrCreate(
            ['tenant_id' => $tenantId, 'name' => trim($data['name'])],
            ['color' => $data['color'] ?? '#22d3ee'],
        );
    }

    public function ticketTags(int $ticketId, int $tenantId)
    {
        return $this->ticket($ticketId, $tenantId)->tags()->orderBy('name')->get();
    }

    /** Attach an existing tag by id, or create-and-attach by name. */
    public function attach(int $ticketId, array $data, int $tenantId)
    {
        $ticket = $this->ticket($ticketId, $tenantId);

        if (! empty($data['tag_id'])) {
            $tag = TicketTag::forTenant($tenantId)->find($data['tag_id']);
            if (! $tag) {
                throw new BusinessException('Tag not found.', 404);
            }
        } else {
            $tag = $this->createTag($data, $tenantId);
        }

        $ticket->tags()->syncWithoutDetaching([$tag->id => ['tenant_id' => $tenantId]]);

        return $ticket->tags()->orderBy('name')->get();
    }

    public function detach(int $ticketId, int $tagId, int $tenantId)
    {
        $ticket = $this->ticket($ticketId, $tenantId);
        $ticket->tags()->detach($tagId);

        return $ticket->tags()->orderBy('name')->get();
    }

    private function ticket(int $ticketId, int $tenantId): Ticket
    {
        $ticket = Ticket::forTenant($tenantId)->find($ticketId);
        if (! $ticket) {
            throw new BusinessException('Ticket not found.', 404);
        }

        return $ticket;
    }
}
