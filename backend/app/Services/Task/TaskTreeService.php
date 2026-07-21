<?php

namespace App\Services\Task;

use App\Exceptions\BusinessException;
use App\Models\Task\Task;
use App\Models\Task\TaskChecklistItem;
use App\Services\StatusService;
use Illuminate\Support\Facades\DB;

/**
 * The subtask tree: Website -> Design -> Homepage mockup -> Pick colours -> ...
 *
 * ALL recursion lives here. Nothing else in the module walks parents or
 * children, so there is exactly one place where a cycle, a depth explosion or a
 * miscounted percentage can happen — and one place to fix it.
 *
 * The load strategy is the important bit. Every node carries `root_id`, so a
 * whole tree of any depth is ONE query:
 *
 *     Task::where('root_id', $id)->get()
 *
 * ...assembled into a nested shape in PHP. No query-per-level, and no recursive
 * CTE (which SQLite and MySQL disagree about). Progress is then a single
 * in-memory pass over that array rather than a query per node.
 */
class TaskTreeService
{
    /**
     * How deep nesting may go. The spec says "infinite"; in practice infinite
     * means "deeper than anyone will ever go", and an actual unbounded loop is
     * how a bad import or a bug produces a thousand-link chain that no UI can
     * draw and no walk can finish. Ten is far past real use.
     */
    public const MAX_DEPTH = 10;

    public function __construct(private StatusService $statuses)
    {
    }

    /* ── Reading ────────────────────────────────────────────────── */

    /**
     * The whole subtree under $taskId (excluding the task itself), nested, with
     * each node carrying its own rolled-up progress.
     *
     * @return array<int,array> nested nodes, each with a 'children' key
     */
    public function tree(int $taskId, int $tenantId): array
    {
        $root = Task::forTenant($tenantId)->findOrFail($taskId);
        [$byParent, $checklist] = $this->load($root, $tenantId);

        return $this->build($root->id, $byParent, $checklist, $this->closedKey($tenantId));
    }

    /**
     * Rolled-up progress for one task.
     *
     * A LEAF is the smallest unit of work: a checklist item, or a subtask with
     * no children and no checklist of its own. A node that HAS children
     * contributes only its descendants — counting the node as well as the work
     * inside it would double-count the same effort.
     *
     * @return array{percent:int,done:int,total:int,subtasks:int}
     */
    public function progressFor(int $taskId, int $tenantId): array
    {
        $root = Task::forTenant($tenantId)->findOrFail($taskId);

        return $this->progressForTask($root, $tenantId);
    }

    /** Same as progressFor() but for a task you already have in hand. */
    public function progressForTask(Task $task, int $tenantId): array
    {
        [$byParent, $checklist] = $this->load($task, $tenantId);
        $tally = $this->tally($task->id, $byParent, $checklist, $this->closedKey($tenantId));

        return [
            'percent'  => $tally['total'] > 0 ? (int) round($tally['done'] / $tally['total'] * 100) : 0,
            'done'     => $tally['done'],
            'total'    => $tally['total'],
            'subtasks' => $this->countDescendants($task->id, $byParent),
        ];
    }

    /**
     * Progress across MANY root tasks at once — one query for the lot.
     * Used by the project percentage, which would otherwise fire a tree load per
     * task on a project with fifty of them.
     *
     * @param  int[]  $taskIds  top-level task ids
     * @return array{percent:int,done:int,total:int}
     */
    public function progressForMany(array $taskIds, int $tenantId): array
    {
        if (! $taskIds) {
            return ['percent' => 0, 'done' => 0, 'total' => 0];
        }

        // Every node in every one of those trees, in a single query.
        $nodes = Task::forTenant($tenantId)
            ->whereIn('root_id', $taskIds)
            ->get(['id', 'parent_id', 'root_id', 'status']);

        $byParent = $nodes->groupBy(fn ($t) => (int) ($t->parent_id ?? 0))->all();
        $checklist = $this->checklistFor($nodes->pluck('id')->all(), $tenantId);
        $closed = $this->closedKey($tenantId);

        $done = $total = 0;
        foreach ($taskIds as $id) {
            $t = $this->tally((int) $id, $byParent, $checklist, $closed);
            $done += $t['done'];
            $total += $t['total'];
        }

        return [
            'percent' => $total > 0 ? (int) round($done / $total * 100) : 0,
            'done'    => $done,
            'total'   => $total,
        ];
    }

    /** Every descendant id of a task, at any depth. */
    public function descendantIds(int $taskId, int $tenantId): array
    {
        $root = Task::forTenant($tenantId)->findOrFail($taskId);
        [$byParent] = $this->load($root, $tenantId, withChecklist: false);

        return $this->collectDescendants($taskId, $byParent);
    }

    /** Breadcrumb from the top of the tree down to (and including) this task. */
    public function ancestryOf(Task $task, int $tenantId): array
    {
        if (! $task->parent_id) {
            return [['id' => $task->id, 'name' => $task->name]];
        }

        $nodes = Task::forTenant($tenantId)
            ->where('root_id', $task->root_id ?? $task->id)
            ->get(['id', 'parent_id', 'name'])->keyBy('id');

        $chain = [];
        $cursor = $task->id;
        // Bounded by MAX_DEPTH so a corrupted parent chain can't spin forever.
        for ($i = 0; $i <= self::MAX_DEPTH + 1 && $cursor; $i++) {
            $node = $nodes->get($cursor);
            if (! $node) {
                break;
            }
            array_unshift($chain, ['id' => (int) $node->id, 'name' => $node->name]);
            $cursor = $node->parent_id ? (int) $node->parent_id : null;
        }

        return $chain;
    }

    /* ── Writing ────────────────────────────────────────────────── */

    /**
     * Stamp a brand-new task's place in the tree. Called on create, before save.
     * Returns the [parent_id, root_id, depth] triple to write.
     */
    public function placeUnder(?int $parentId, int $tenantId): array
    {
        if (! $parentId) {
            return ['parent_id' => null, 'root_id' => null, 'depth' => 0];
        }

        $parent = Task::forTenant($tenantId)->find($parentId)
            ?? throw new BusinessException('That parent task does not exist.', 404);

        $depth = (int) $parent->depth + 1;
        if ($depth > self::MAX_DEPTH) {
            throw new BusinessException(
                'Subtasks can only be nested '.self::MAX_DEPTH.' levels deep. Move some of this work into its own task instead.',
                422
            );
        }

        return [
            'parent_id' => $parent->id,
            'root_id'   => $parent->root_id ?: $parent->id,
            'depth'     => $depth,
        ];
    }

    /**
     * Re-parent a task (and everything under it).
     *
     * The two guards here are what stop the tree eating itself:
     *  • you cannot move a task under its own descendant — that makes a ring
     *    with no root, and every walk over it runs forever;
     *  • the deepest node in the moved branch must still fit under MAX_DEPTH.
     */
    public function moveTo(int $taskId, ?int $newParentId, int $tenantId): Task
    {
        $task = Task::forTenant($tenantId)->findOrFail($taskId);

        if ($newParentId && (int) $newParentId === $taskId) {
            throw new BusinessException('A task cannot be its own subtask.', 422);
        }

        $descendants = $this->descendantIds($taskId, $tenantId);

        if ($newParentId && in_array((int) $newParentId, $descendants, true)) {
            throw new BusinessException(
                'You cannot move a task underneath one of its own subtasks — that would make a loop.',
                422
            );
        }

        $place = $this->placeUnder($newParentId, $tenantId);

        // How far below this task the branch already reaches, so the whole branch
        // is checked against the cap, not just the node being dragged.
        $branchDepth = 0;
        if ($descendants) {
            $deepest = (int) Task::forTenant($tenantId)->whereIn('id', $descendants)->max('depth');
            $branchDepth = $deepest - (int) $task->depth;
        }
        if ($place['depth'] + $branchDepth > self::MAX_DEPTH) {
            throw new BusinessException(
                'That move would nest subtasks more than '.self::MAX_DEPTH.' levels deep.',
                422
            );
        }

        $shift = $place['depth'] - (int) $task->depth;
        $newRoot = $place['root_id'] ?: $task->id;

        DB::transaction(function () use ($task, $place, $descendants, $shift, $newRoot) {
            $task->forceFill([
                'parent_id' => $place['parent_id'],
                'root_id'   => $newRoot,
                'depth'     => $place['depth'],
            ])->save();

            // The whole branch moves with it — one write, not one per node.
            if ($descendants) {
                Task::whereIn('id', $descendants)->update([
                    'root_id' => $newRoot,
                    'depth'   => DB::raw('depth + ('.$shift.')'),
                ]);
            }
        });

        return $task->fresh();
    }

    /* ── Internals ──────────────────────────────────────────────── */

    /**
     * One query for the tree, one for its checklist items.
     *
     * @return array{0:array<int,mixed>,1:array<int,array{done:int,total:int}>}
     */
    private function load(Task $root, int $tenantId, bool $withChecklist = true): array
    {
        $nodes = Task::forTenant($tenantId)
            ->where('root_id', $root->root_id ?: $root->id)
            ->with('assignees.user:id,name')
            ->get();

        $byParent = $nodes->groupBy(fn ($t) => (int) ($t->parent_id ?? 0))->all();

        $checklist = $withChecklist
            ? $this->checklistFor($nodes->pluck('id')->push($root->id)->unique()->all(), $tenantId)
            : [];

        return [$byParent, $checklist];
    }

    /** done/total checklist counts per task id, in one grouped query. */
    private function checklistFor(array $taskIds, int $tenantId): array
    {
        if (! $taskIds) {
            return [];
        }

        return TaskChecklistItem::forTenant($tenantId)
            ->whereIn('task_id', $taskIds)
            ->selectRaw('task_id, COUNT(*) as total, SUM(CASE WHEN finished = 1 THEN 1 ELSE 0 END) as done')
            ->groupBy('task_id')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->task_id => ['done' => (int) $r->done, 'total' => (int) $r->total]])
            ->all();
    }

    /**
     * Count the leaves under a task. See progressFor() for what a leaf is.
     *
     * @return array{done:int,total:int}
     */
    private function tally(int $taskId, array $byParent, array $checklist, string $closed): array
    {
        $done = $checklist[$taskId]['done'] ?? 0;
        $total = $checklist[$taskId]['total'] ?? 0;

        foreach ($byParent[$taskId] ?? [] as $child) {
            $cid = (int) $child->id;
            $hasChildren = ! empty($byParent[$cid]);
            $hasChecklist = ! empty($checklist[$cid]['total']);

            if (! $hasChildren && ! $hasChecklist) {
                // A leaf: the smallest piece of real work in this branch.
                $total++;
                $done += $child->status === $closed ? 1 : 0;

                continue;
            }

            $sub = $this->tally($cid, $byParent, $checklist, $closed);
            $done += $sub['done'];
            $total += $sub['total'];
        }

        return ['done' => $done, 'total' => $total];
    }

    /** Nested array for the UI, each node carrying its own progress. */
    private function build(int $parentId, array $byParent, array $checklist, string $closed): array
    {
        $out = [];

        foreach ($byParent[$parentId] ?? [] as $node) {
            $id = (int) $node->id;
            $tally = $this->tally($id, $byParent, $checklist, $closed);

            $out[] = [
                'id'          => $id,
                'name'        => $node->name,
                'status'      => $node->status,
                'is_done'     => $node->status === $closed,
                'priority'    => $node->priority,
                'due_date'    => optional($node->due_date)->toDateString(),
                'created_at'  => optional($node->created_at)->toIso8601String(),
                'depth'       => (int) $node->depth,
                'parent_id'   => (int) $node->parent_id,
                // Each subtask owns its assignees outright — nothing here is
                // inherited from, or written back to, the parent.
                'assignees'   => $node->assignees->map(fn ($a) => [
                    'user_id' => (int) $a->user_id,
                    'name'    => $a->user->name ?? null,
                ])->all(),
                'checklist'   => $checklist[$id] ?? ['done' => 0, 'total' => 0],
                'progress'    => [
                    'percent' => $tally['total'] > 0 ? (int) round($tally['done'] / $tally['total'] * 100) : 0,
                    'done'    => $tally['done'],
                    'total'   => $tally['total'],
                ],
                'children'    => $this->build($id, $byParent, $checklist, $closed),
            ];
        }

        return $out;
    }

    /** @return int[] */
    private function collectDescendants(int $taskId, array $byParent): array
    {
        $ids = [];
        foreach ($byParent[$taskId] ?? [] as $child) {
            $ids[] = (int) $child->id;
            $ids = array_merge($ids, $this->collectDescendants((int) $child->id, $byParent));
        }

        return $ids;
    }

    private function countDescendants(int $taskId, array $byParent): int
    {
        return count($this->collectDescendants($taskId, $byParent));
    }

    private function closedKey(int $tenantId): string
    {
        return $this->statuses->closedKey('task', $tenantId) ?: 'complete';
    }
}
