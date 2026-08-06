<?php

namespace App\Services\Shared;

use App\Exceptions\BusinessException;
use App\Models\Shared\Poll;
use App\Models\Shared\PollVote;
use App\Models\User;
use App\Services\Helpdesk\HelpdeskService;
use App\Services\Project\ProjectService;
use App\Services\Task\TaskService;
use Illuminate\Support\Facades\DB;

/**
 * The one poll engine for Tasks, Helpdesk and Projects (owner: Shivam).
 *
 * A poll hangs on a context (task|ticket|project). Rather than re-implement
 * "can this user see it", each operation delegates to the OWNING module's
 * assert…Visible() — so a poll can never be seen or voted by someone who could
 * not already see the task/ticket/project it belongs to. Only the poll's
 * creator (or an admin) may close or delete it.
 */
class PollService
{
    public const CONTEXTS = ['task', 'ticket', 'project'];

    public function __construct(
        private ProjectService $projects,
        private TaskService $tasks,
        private HelpdeskService $helpdesk,
    ) {
    }

    /** Delegate visibility to the module that owns the context, or 404/403 there. */
    public function assertContextVisible(string $type, int $id, User $user): void
    {
        $isAdmin = $user->role === 'admin';
        match ($type) {
            'project' => $this->projects->assertProjectVisible($id, $user->tenant_id, $user->id, $isAdmin),
            'task'    => $this->tasks->assertTaskVisible($id, $user->tenant_id, $user->id, $isAdmin),
            'ticket'  => $this->helpdesk->assertTicketVisible($id, $user->tenant_id, $user->id, $user->role, $user->email),
            default   => throw new BusinessException('Unknown poll context.', 422),
        };
    }

    /** Polls on a context, each already reduced to a per-user results payload. */
    public function listForContext(string $type, int $id, User $user): array
    {
        $this->assertContextVisible($type, $id, $user);

        return Poll::forTenant($user->tenant_id)
            ->where('context_type', $type)
            ->where('context_id', $id)
            ->with(['options', 'creator:id,name'])
            ->withCount('votes')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Poll $p) => $this->payload($p, $user))
            ->all();
    }

    public function create(string $type, int $id, User $user, array $data): array
    {
        $this->assertContextVisible($type, $id, $user);

        $options = collect($data['options'] ?? [])
            ->map(fn ($o) => trim((string) $o))
            ->filter()
            ->unique()
            ->values();

        if ($options->count() < 2) {
            throw new BusinessException('A poll needs at least two options.', 422);
        }

        return DB::transaction(function () use ($type, $id, $user, $data, $options) {
            $poll = Poll::create([
                'tenant_id'      => $user->tenant_id,
                'context_type'   => $type,
                'context_id'     => $id,
                'question'       => trim($data['question']),
                'allow_multiple' => (bool) ($data['allow_multiple'] ?? false),
                'closes_at'      => $data['closes_at'] ?? null,
                'created_by'     => $user->id,
            ]);

            $options->each(fn ($label, $i) => $poll->options()->create(['label' => $label, 'position' => $i]));

            return $this->payload($poll->fresh(['options', 'creator:id,name'])->loadCount('votes'), $user);
        });
    }

    /**
     * Set this user's selection to exactly $optionIds (a toggle-set model): the
     * user's previous votes on this poll are cleared, then the new ones written.
     * Single-choice polls accept exactly one id; deselecting all is allowed.
     */
    public function vote(Poll $poll, User $user, array $optionIds): array
    {
        $this->assertContextVisible($poll->context_type, $poll->context_id, $user);

        if ($poll->isClosed()) {
            throw new BusinessException('This poll is closed.', 422);
        }

        $valid = $poll->options()->pluck('id')->all();
        $chosen = array_values(array_unique(array_filter(
            array_map('intval', $optionIds),
            fn ($oid) => in_array($oid, $valid, true),
        )));

        if (! $poll->allow_multiple && count($chosen) > 1) {
            throw new BusinessException('This poll allows only one choice.', 422);
        }

        DB::transaction(function () use ($poll, $user, $chosen) {
            PollVote::where('poll_id', $poll->id)->where('user_id', $user->id)->delete();
            foreach ($chosen as $oid) {
                PollVote::create([
                    'tenant_id'      => $poll->tenant_id,
                    'poll_id'        => $poll->id,
                    'poll_option_id' => $oid,
                    'user_id'        => $user->id,
                ]);
            }
        });

        return $this->payload($poll->fresh(['options', 'creator:id,name'])->loadCount('votes'), $user);
    }

    public function close(Poll $poll, User $user): array
    {
        $this->assertManage($poll, $user);
        // Closing now = stamp the deadline to this instant.
        $poll->update(['closes_at' => now()]);

        return $this->payload($poll->fresh(['options', 'creator:id,name'])->loadCount('votes'), $user);
    }

    public function deletePoll(Poll $poll, User $user): void
    {
        $this->assertManage($poll, $user);
        $poll->delete();
    }

    private function assertManage(Poll $poll, User $user): void
    {
        if ($user->role !== 'admin' && $poll->created_by !== $user->id) {
            throw new BusinessException('Only the poll creator or an admin can do that.', 403);
        }
    }

    /** Reduce a poll to the shape the frontend renders (counts, %, my picks). */
    private function payload(Poll $poll, User $user): array
    {
        // votes-per-option and the set of options THIS user picked, in one read.
        $rows = PollVote::where('poll_id', $poll->id)->get(['poll_option_id', 'user_id']);
        $perOption = $rows->groupBy('poll_option_id')->map->count();
        $voters = $rows->pluck('user_id')->unique()->count();
        $mine = $rows->where('user_id', $user->id)->pluck('poll_option_id')->map(fn ($v) => (int) $v)->all();

        $options = $poll->options->map(function ($o) use ($perOption, $voters) {
            $count = (int) ($perOption[$o->id] ?? 0);

            return [
                'id'    => $o->id,
                'label' => $o->label,
                'votes' => $count,
                'pct'   => $voters > 0 ? (int) round($count / $voters * 100) : 0,
            ];
        })->all();

        return [
            'id'             => $poll->id,
            'question'       => $poll->question,
            'allow_multiple' => $poll->allow_multiple,
            'closes_at'      => optional($poll->closes_at)->toIso8601String(),
            'is_closed'      => $poll->isClosed(),
            'created_by'     => $poll->created_by,
            'created_by_name'=> $poll->creator?->name,
            'created_at'     => optional($poll->created_at)->toIso8601String(),
            'total_voters'   => $voters,
            'options'        => $options,
            'my_votes'       => $mine,
            'can_manage'     => $user->role === 'admin' || $poll->created_by === $user->id,
        ];
    }
}
