<?php

namespace Sire\Dto;

/**
 * SIRE SDK — one thing that should be told to one person.
 *
 * THE SPLIT THIS OBJECT ENCODES
 *
 * SIRE decides WHAT happened, WHO should hear about it and HOW URGENT it is.
 * The host decides WHETHER that becomes an email, a database row, a push, a
 * Slack message or nothing at all.
 *
 * By the time a host provider receives one of these, SIRE has already applied
 * every rule only SIRE could know: the actor never hears about their own action,
 * recipients are de-duplicated, low-value events are collapsed into a one-hour
 * window, and SLA warnings fire once per clock rather than once per sweep. A host
 * that re-derives recipients will double-send.
 *
 * `url` is a SIRE-relative path (`/app/sire/cases/412`), not an absolute URL —
 * only the host knows its own domain, mail templates and deep-link scheme.
 */
final class SireNotification
{
    public const PRIORITY_LOW    = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH   = 'high';
    public const PRIORITY_URGENT = 'urgent';

    /**
     * @param  string               $event      a SireEvents::* constant
     * @param  int                  $recipientId host user id — exactly one person
     * @param  string               $title      short, already localised to plain English
     * @param  string               $body       one or two sentences
     * @param  string|null          $url        SIRE-relative path the recipient should land on
     * @param  string               $priority   one of the PRIORITY_* constants
     * @param  array<string, mixed> $metadata   issue number, status, severity — for templating
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly string $event,
        public readonly int $recipientId,
        public readonly string $title,
        public readonly string $body = '',
        public readonly ?string $url = null,
        public readonly string $priority = self::PRIORITY_NORMAL,
        public readonly array $metadata = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tenant_id'    => $this->tenantId,
            'event'        => $this->event,
            'recipient_id' => $this->recipientId,
            'title'        => $this->title,
            'body'         => $this->body,
            'url'          => $this->url,
            'priority'     => $this->priority,
            'metadata'     => $this->metadata,
        ];
    }

    /** A copy addressed to somebody else. Fan-out builds one per recipient. */
    public function forRecipient(int $recipientId): self
    {
        return new self(
            $this->tenantId, $this->event, $recipientId,
            $this->title, $this->body, $this->url, $this->priority, $this->metadata,
        );
    }
}
