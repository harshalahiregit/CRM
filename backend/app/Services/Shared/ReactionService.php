<?php

namespace App\Services\Shared;

use App\Models\Shared\MessageReaction;
use App\Models\User;

/**
 * Emoji reactions for the message threads (owner: Shivam).
 *
 * Reactions only ever render on messages the user can already see (the threads
 * enforce their own visibility), so this engine's boundary is the tenant: it
 * never reads or writes across tenants, and toggling is idempotent per
 * (subject, user, emoji).
 */
class ReactionService
{
    public const SUBJECTS = ['task_comment', 'ticket_reply', 'discussion_comment'];

    // The reaction palette the UI offers — a closed set so the column can't be
    // stuffed with arbitrary text.
    public const EMOJIS = ['👍', '❤️', '😄', '🎉', '😮', '😢', '🙏', '🔥'];

    /** Toggle one emoji for this user on one message; returns that message's fresh summary. */
    public function toggle(string $subjectType, int $subjectId, string $emoji, User $user): array
    {
        $existing = MessageReaction::forTenant($user->tenant_id)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('user_id', $user->id)
            ->where('emoji', $emoji)
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            MessageReaction::create([
                'tenant_id'    => $user->tenant_id,
                'subject_type' => $subjectType,
                'subject_id'   => $subjectId,
                'user_id'      => $user->id,
                'emoji'        => $emoji,
            ]);
        }

        return $this->summaryFor($subjectType, [$subjectId], $user)[$subjectId] ?? [];
    }

    /**
     * Reaction summary for a batch of messages, keyed by subject_id. Each entry
     * is a list of { emoji, count, mine } — one request for a whole thread.
     *
     * @return array<int, array<int, array{emoji:string,count:int,mine:bool}>>
     */
    public function summaryFor(string $subjectType, array $subjectIds, User $user): array
    {
        $ids = array_values(array_filter(array_map('intval', $subjectIds)));
        if (empty($ids)) {
            return [];
        }

        $rows = MessageReaction::forTenant($user->tenant_id)
            ->where('subject_type', $subjectType)
            ->whereIn('subject_id', $ids)
            ->get(['subject_id', 'emoji', 'user_id']);

        $out = [];
        foreach ($rows->groupBy('subject_id') as $sid => $group) {
            $byEmoji = [];
            foreach ($group->groupBy('emoji') as $emoji => $g) {
                $byEmoji[] = [
                    'emoji' => $emoji,
                    'count' => $g->count(),
                    'mine'  => $g->contains('user_id', $user->id),
                ];
            }
            $out[(int) $sid] = $byEmoji;
        }

        return $out;
    }
}
