<?php

namespace App\Services\Sales;

use App\Exceptions\UnauthorizedTenantException;
use App\Models\Sales\Lead;
use App\Models\Sales\LeadActivity;
use App\Models\Sales\Reminder;
use App\Models\Sales\Task;
use Illuminate\Support\Facades\Log;

/**
 * Rule-based sales insights. Deliberately heuristic and self-contained —
 * NO LLM/ML calls, no external services, no training. Everything is derived
 * from data the CRM already stores (lead score/temperature/conversion_chance,
 * activity recency, reminder state, task due dates).
 */
class SalesInsightService
{
    /**
     * Blended win probability (0-100) from the signals we already have.
     * conversion_chance is the human estimate and carries the most weight;
     * lead_score (the existing scoring engine) and temperature refine it.
     */
    public function winProbability(Lead $lead, int $tenantId): array
    {
        $this->assertTenant($lead, $tenantId);

        $chance = (int) ($lead->conversion_chance ?? 0);
        $score  = (int) ($lead->lead_score ?? 0);

        // 70% human estimate, 30% computed score.
        $probability = (int) round(($chance * 0.7) + ($score * 0.3));

        // Temperature nudge, then clamp.
        $probability += match ($lead->lead_temperature) {
            'Hot'  => 5,
            'Cold' => -5,
            default => 0,
        };

        // A lost/junk lead can't be won.
        if ($lead->lost || $lead->junk) {
            $probability = 0;
        }

        $probability = max(0, min(100, $probability));

        return [
            'probability' => $probability,
            'band'        => $probability >= 70 ? 'High' : ($probability >= 40 ? 'Medium' : 'Low'),
            'basis'       => [
                'conversion_chance' => $chance,
                'lead_score'        => $score,
                'temperature'       => $lead->lead_temperature,
            ],
        ];
    }

    /**
     * Heuristic "what should I do next on this lead" — first matching rule wins.
     */
    public function nextBestAction(Lead $lead, int $tenantId): array
    {
        $this->assertTenant($lead, $tenantId);

        if ($lead->lost || $lead->junk) {
            return $this->action('restore', 'This lead is archived — restore it to work it again.');
        }

        if (empty($lead->assigned_to)) {
            return $this->action('assign', 'No owner assigned — assign a sales executive.');
        }

        // An overdue follow-up outranks everything else.
        $overdue = Reminder::forTenant($tenantId)
            ->where('remindable_type', Lead::class)
            ->where('remindable_id', $lead->id)
            ->whereNull('completed_at')
            ->where('due_at', '<', now())
            ->exists();

        if ($overdue) {
            return $this->action('follow_up', 'A follow-up on this lead is overdue — complete it.');
        }

        $hasUpcoming = Reminder::forTenant($tenantId)
            ->where('remindable_type', Lead::class)
            ->where('remindable_id', $lead->id)
            ->whereNull('completed_at')
            ->exists();

        // Staleness: how long since anything happened on this lead?
        $lastActivity = LeadActivity::forTenant($tenantId)
            ->where('lead_id', $lead->id)
            ->latest()
            ->first();

        $daysSince = $lastActivity
            ? $lastActivity->created_at->diffInDays(now())
            : ($lead->created_at ? $lead->created_at->diffInDays(now()) : 0);

        if (! $hasUpcoming && $daysSince >= 5) {
            return $this->action('follow_up', "No contact in {$daysSince} days — schedule a follow-up.");
        }

        if (empty($lead->email) && empty($lead->phone)) {
            return $this->action('enrich', 'No email or phone on record — enrich the contact details.');
        }

        if (! $lead->proposals()->exists() && (int) ($lead->conversion_chance ?? 0) >= 50) {
            return $this->action('proposal', 'Lead is warm with no proposal yet — send one.');
        }

        if (empty($lead->expected_close_date)) {
            return $this->action('qualify', 'No expected close date — qualify and set one.');
        }

        return $this->action('nurture', 'On track — keep nurturing this lead.');
    }

    /**
     * Pure ordering of open tasks: overdue first, then soonest due, then
     * priority weight. No model calls — just a deterministic sort.
     */
    public function prioritizeTasks(int $tenantId, ?int $userId = null, int $limit = 20)
    {
        $weight = ['urgent' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];

        $query = Task::forTenant($tenantId)
            ->whereNull('date_finished')
            ->with('status:id,name,color');

        if ($userId) {
            $query->whereHas('assignees', fn ($q) => $q->where('users.id', $userId));
        }

        return $query->get()
            ->sortBy(fn (Task $t) => [
                $t->due_date && $t->due_date->isPast() ? 0 : 1,       // overdue first
                $t->due_date?->timestamp ?? PHP_INT_MAX,               // then soonest due
                $weight[$t->priority] ?? 9,                            // then priority
            ])
            ->take($limit)
            ->values();
    }

    private function action(string $type, string $message): array
    {
        return ['action' => $type, 'message' => $message];
    }

    private function assertTenant(Lead $lead, int $tenantId): void
    {
        if ($lead->tenant_id !== $tenantId) {
            Log::channel('sales')->warning('Unauthorized lead insight access attempt', [
                'lead_id' => $lead->id, 'tenant_id' => $tenantId,
            ]);
            throw new UnauthorizedTenantException();
        }
    }
}
