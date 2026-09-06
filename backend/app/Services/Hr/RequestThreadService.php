<?php

namespace App\Services\Hr;

use App\Models\Hr\HrRequestMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Writing to and reading a request's conversation.
 *
 * Every entry goes through here so the three kinds stay distinguishable and so an
 * event's sentence is written once, in one place, rather than assembled
 * differently by each caller.
 */
class RequestThreadService
{
    /**
     * A message from either side. Visible to both.
     */
    public function message(Model $subject, User $author, string $body): HrRequestMessage
    {
        return $this->write($subject, HrRequestMessage::KIND_MESSAGE, $body, $author);
    }

    /**
     * An admin talking to other admins. The employee never sees it.
     *
     * Worth having rather than assuming admins will not need it: without a
     * private place inside the record, that conversation happens on WhatsApp and
     * the reasoning behind a decision leaves the system entirely.
     */
    public function note(Model $subject, User $author, string $body): HrRequestMessage
    {
        return $this->write($subject, HrRequestMessage::KIND_NOTE, $body, $author);
    }

    /**
     * Something happened. Written by the state change itself.
     *
     * $body is the sentence as it should read forever. It is stored, not derived,
     * so changing how this system phrases things later cannot rewrite what the
     * record says happened.
     *
     * @param  array  $meta  whatever the event needs to be reconstructed — the old
     *                       and new amount, the hold reason, the payment reference
     */
    public function event(
        Model $subject,
        string $eventType,
        string $body,
        ?User $actor = null,
        array $meta = []
    ): HrRequestMessage {
        return $this->write($subject, HrRequestMessage::KIND_EVENT, $body, $actor, $eventType, $meta);
    }

    /**
     * The thread, oldest first.
     *
     * @param  bool  $asEmployee  when true, internal notes are excluded. The caller
     *                            decides this from who is asking — it is not a
     *                            default, because getting it wrong leaks the notes.
     */
    public function forSubject(Model $subject, bool $asEmployee): Collection
    {
        return HrRequestMessage::query()
            ->where('tenant_id', $subject->tenant_id)
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->when($asEmployee, fn ($q) => $q->visibleToEmployee())
            ->with(['author:id,name', 'attachments'])
            ->orderBy('id')
            ->get();
    }

    private function write(
        Model $subject,
        string $kind,
        string $body,
        ?User $author,
        ?string $eventType = null,
        array $meta = []
    ): HrRequestMessage {
        $body = trim($body);

        if ($body === '') {
            // An empty entry is worse than none: it takes up a line in the thread
            // and says nothing, and a hold with no reason is exactly the thing
            // this design exists to prevent.
            throw new \InvalidArgumentException('A thread entry needs something in it.');
        }

        return HrRequestMessage::create([
            // From the subject, never from the actor: an admin acting on a record
            // is acting within that record's tenant, and taking it from the actor
            // is how a cross-tenant write happens without anyone noticing.
            'tenant_id'    => $subject->tenant_id,
            'subject_type' => $subject->getMorphClass(),
            'subject_id'   => $subject->getKey(),
            'author_id'    => $author?->id,
            'kind'         => $kind,
            'body'         => $body,
            'event_type'   => $eventType,
            'meta'         => $meta ?: null,
        ]);
    }
}
