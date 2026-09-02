<?php

namespace App\Services\Purchase;

use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Accept a meeting's agenda, actions, decisions and issues as ONE nested
 * payload, the way the shared meeting form sends them.
 *
 * The two engines disagree about how a meeting's contents arrive. The shared
 * form builds the whole meeting — agenda rows, action items, decisions, issues
 * — and posts it in a single request; Purchase had only per-item CRUD
 * endpoints, so the same form against Purchase would have created an empty
 * meeting and silently dropped everything typed into it.
 *
 * Fanning the collections out from the browser instead would mean a dozen
 * sequential requests with no transaction: a failure halfway leaves a meeting
 * holding half its agenda and no way to tell which half. So the fan-out lives
 * here, inside one transaction, and delegates each row to the sub-service that
 * already owns it (refs, audit entries and participant resolution included)
 * rather than reimplementing any of that.
 *
 * Rows are matched by `id` when the client sends one and created otherwise;
 * anything the payload omits is deleted, so the form's list is the record.
 */
class PurchaseKickoffContentService
{
    public function __construct(
        private PurchaseMomAgendaService $agenda,
        private PurchaseMomActionService $actions,
        private PurchaseMomDecisionService $decisions,
        private PurchaseMomIssueService $issues,
    ) {}

    /** Whether a payload carries any content worth syncing. */
    public function hasContent(array $payload): bool
    {
        foreach (['agenda_items', 'mom_items', 'decisions', 'issues'] as $key) {
            if (! empty($payload[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sync every collection present in the payload.
     *
     * A collection that is ABSENT is left alone; a collection that is present
     * but empty clears its rows. The distinction matters because the detail
     * page saves one section at a time and must not wipe the others.
     */
    public function sync(PurchaseKickoffMeeting $meeting, array $payload, User $actor): PurchaseKickoffMeeting
    {
        DB::transaction(function () use ($meeting, $payload, $actor) {
            if (array_key_exists('agenda_items', $payload)) {
                $this->syncAgenda($meeting, (array) $payload['agenda_items'], $actor);
            }
            if (array_key_exists('mom_items', $payload)) {
                $this->syncActions($meeting, (array) $payload['mom_items'], $actor);
            }
            if (array_key_exists('decisions', $payload)) {
                $this->syncDecisions($meeting, (array) $payload['decisions'], $actor);
            }
            if (array_key_exists('issues', $payload)) {
                $this->syncIssues($meeting, (array) $payload['issues'], $actor);
            }
        });

        return $meeting->fresh(['agendaItems', 'actionItems', 'momDecisions', 'momIssues', 'participants']);
    }

    /* ── collections ──────────────────────────────────────────── */

    private function syncAgenda(PurchaseKickoffMeeting $meeting, array $rows, User $actor): void
    {
        $keep = [];
        $order = 0;

        foreach ($rows as $row) {
            if (trim((string) ($row['item'] ?? '')) === '') {
                continue;
            }

            $data = [
                'item' => $row['item'],
                'description' => $row['description'] ?? null,
                // The shared form types an owner as free text; Purchase stores
                // that in owner_names and resolves a participant separately.
                'owner_names' => $row['owner'] ?? $row['owner_names'] ?? null,
                'duration_minutes' => $row['duration_minutes'] ?? null,
                'priority' => $row['priority'] ?? null,
                'discussion' => $row['discussion'] ?? null,
                'decision' => $row['decision'] ?? null,
                'sort_order' => $order++,
            ];

            $existing = $this->existing($meeting->agendaItems(), $row);
            $keep[] = $existing
                ? $this->agenda->update($existing, $data, $actor)->id
                : $this->agenda->create($meeting, $data, $actor)->id;
        }

        $this->prune($meeting->agendaItems(), $keep);
    }

    private function syncActions(PurchaseKickoffMeeting $meeting, array $rows, User $actor): void
    {
        $keep = [];
        $order = 0;

        foreach ($rows as $row) {
            // Strip markup before testing for emptiness — the shared editor is
            // rich text, so a "blank" row still arrives as <p></p>.
            if (trim(strip_tags((string) ($row['description'] ?? ''))) === '') {
                continue;
            }

            $data = [
                'description' => $row['description'],
                'responsible_names' => $row['responsible'] ?? $row['responsible_names'] ?? null,
                'responsible_org' => $row['responsible_org'] ?? null,
                'priority' => $row['priority'] ?? null,
                'target_date' => $row['target_date'] ?? null,
                // Shared calls it "remarks"; the column is `remark`.
                'remark' => $row['remarks'] ?? $row['remark'] ?? null,
                'sort_order' => $order++,
            ];

            $existing = $this->existing($meeting->actionItems(), $row);
            $keep[] = $existing
                ? $this->actions->update($existing, $data, $actor)->id
                : $this->actions->create($meeting, $data, $actor)->id;
        }

        $this->prune($meeting->actionItems(), $keep);
    }

    private function syncDecisions(PurchaseKickoffMeeting $meeting, array $rows, User $actor): void
    {
        $keep = [];
        $order = 0;

        foreach ($rows as $row) {
            if (trim((string) ($row['decision'] ?? '')) === '') {
                continue;
            }

            $data = [
                'decision' => $row['decision'],
                'decided_by_names' => $row['decided_by'] ?? $row['decided_by_names'] ?? null,
                'impact' => $row['impact'] ?? null,
                'effective_date' => $row['effective_date'] ?? null,
                'status' => $row['status'] ?? null,
                'sort_order' => $order++,
            ];

            $existing = $this->existing($meeting->momDecisions(), $row);
            $keep[] = $existing
                ? $this->decisions->update($existing, $data, $actor)->id
                : $this->decisions->create($meeting, $data, $actor)->id;
        }

        $this->prune($meeting->momDecisions(), $keep);
    }

    private function syncIssues(PurchaseKickoffMeeting $meeting, array $rows, User $actor): void
    {
        $keep = [];
        $order = 0;

        foreach ($rows as $row) {
            if (trim((string) ($row['title'] ?? '')) === '') {
                continue;
            }

            $data = [
                'title' => $row['title'],
                'description' => $row['description'] ?? null,
                'category' => $row['category'] ?? null,
                'severity' => $row['severity'] ?? null,
                'owner_names' => $row['owner'] ?? $row['owner_names'] ?? null,
                'due_date' => $row['due_date'] ?? null,
                'sort_order' => $order++,
            ];

            $existing = $this->existing($meeting->momIssues(), $row);
            $keep[] = $existing
                ? $this->issues->update($existing, $data, $actor)->id
                : $this->issues->create($meeting, $data, $actor)->id;
        }

        $this->prune($meeting->momIssues(), $keep);
    }

    /* ── helpers ──────────────────────────────────────────────── */

    /**
     * The row this payload entry refers to, or null for a new one.
     *
     * Only an INTEGER id counts: the shared form gives unsaved rows a temporary
     * client-side key, and treating one of those as a database id would look up
     * — or overwrite — an unrelated record.
     */
    private function existing($relation, array $row)
    {
        $id = $row['id'] ?? null;
        if (! is_int($id) && ! ctype_digit((string) $id)) {
            return null;
        }

        // Scoped through the relation, so an id belonging to another meeting
        // cannot be edited by sending it in this meeting's payload.
        return $relation->getQuery()->find((int) $id);
    }

    /** Delete the rows the payload no longer lists. */
    private function prune($relation, array $keepIds): void
    {
        $q = $relation->getQuery();
        if ($keepIds !== []) {
            $q->whereNotIn('id', $keepIds);
        }
        $q->delete();
    }
}
