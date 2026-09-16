<?php

namespace Sire\Contracts;

use Sire\Dto\SireNotification;

/**
 * SIRE SDK — TELL SOMEONE.
 *
 * SIRE owns the events and the audience. The host owns delivery: whether a
 * notification becomes a database row, an email, a push, a Slack message, or
 * nothing at all.
 *
 * DO NOT RE-DERIVE THE AUDIENCE
 *
 * By the time this is called, SIRE has already applied four rules a generic
 * engine could not know:
 *
 *   1. the actor never hears about their own action — by far the largest source
 *      of pointless traffic in a workflow tool;
 *   2. recipients are de-duplicated, because reporter and assignee are often the
 *      same person;
 *   3. low-value events are collapsed to one per recipient per issue per hour;
 *   4. SLA warnings and breaches fire once per clock, not once per sweep.
 *
 * A host that recomputes recipients from the payload will double-send.
 *
 * DELIVERY MUST NEVER FAIL A TRANSITION
 *
 * Implementations swallow and report their own exceptions. A developer whose
 * "submit to QA" throws because an SMTP host is down has lost work; a QA
 * engineer who misses one email has lost a few minutes. SIRE wraps this call as
 * well, so there are two layers — do not rely on only one.
 */
interface SireNotificationProvider
{
    /**
     * Deliver one notification to one person.
     *
     * @throws never — implementations MUST catch and report internally
     */
    public function send(SireNotification $notification): void;

    /**
     * Deliver several. Separate from send() so a host can batch a digest,
     * a single mail transaction, or one broadcast.
     *
     * @param  array<int, SireNotification> $notifications
     */
    public function sendMany(array $notifications): void;

    /**
     * Has this user opted out of this event?
     *
     * Return TRUE when the host has no preference system, or does not recognise
     * the event. An unwired preference store must not silence SIRE: a missed
     * "QA failed" stalls an issue nobody is watching.
     */
    public function accepts(int $tenantId, int $userId, string $event): bool;
}
