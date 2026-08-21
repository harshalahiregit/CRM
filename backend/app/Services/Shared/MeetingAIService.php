<?php

namespace App\Services\Shared;

use App\Contracts\AI\AIProviderInterface;
use App\Exceptions\AIException;
use App\Exceptions\BusinessException;
use App\Models\Shared\KickoffMeeting;
use App\Support\Shared\KickoffSubject;
use App\Support\Shared\MeetingTypeCatalog;
use Illuminate\Support\Facades\Log;

/**
 * The Meeting engine's AI layer (Meeting.docx §18 — "architect the Meeting engine
 * so AI can be plugged in … suggest agenda, analyse previous MOM, summarise").
 *
 * Deliberately thin: it composes FACTS the engine already has (meeting type, the
 * vendor's live status, carried-forward open items, the captured minutes) and asks
 * the existing AIProviderInterface to draft prose / a list. It invents no data of
 * its own, and a provider outage degrades to a clear message rather than a 500.
 */
class MeetingAIService
{
    public function __construct(
        private AIProviderInterface $ai,
        private VendorLiveStatusService $vendorStatus,
        private KickoffMeetingService $meetings,
    ) {}

    /**
     * Suggest agenda items for a meeting being planned, from its type + the
     * vendor's live status + still-open items carried from earlier meetings.
     *
     * @return array{items: array<int, array{item:string, priority:?string}>, meta: array<string,mixed>}
     */
    public function suggestAgenda(int $tenantId, string $meetingType, ?string $subjectType, $subjectId): array
    {
        $typeLabel = app(MeetingTypeCatalog::class)->label($tenantId, $meetingType);

        $facts = ['meeting_type' => $typeLabel];

        if ($subjectType && $subjectId && KickoffSubject::isValid($subjectType)) {
            // Vendor live status + carried-open items are the real context.
            if ($subjectType === 'vendor') {
                $snap = $this->vendorStatus->snapshot($tenantId, (int) $subjectId);
                $facts['vendor'] = $snap['vendor']['name'] ?? null;
                $facts['live_status'] = collect($snap['sections'])
                    ->map(fn ($s) => $s['label'].': '.$s['value'])->all();
            }
            $carry = $this->meetings->carryForwardItems($tenantId, $subjectType, $subjectId);
            $facts['open_actions'] = collect($carry['actions'])->take(10)
                ->map(fn ($a) => $a['description'])->all();
            $facts['open_issues'] = collect($carry['issues'])->take(10)
                ->map(fn ($i) => $i['title'])->all();
        }

        $system = implode(' ', [
            'You plan agendas for third-party-vendor governance meetings.',
            'From the FACTS provided, propose a focused agenda of 5-9 items.',
            'Cover outstanding items (open actions/issues) and anything the live status flags.',
            'Use ONLY the facts; do not invent incidents, numbers or names.',
            'Return STRICT JSON: {"items":[{"item":"...","priority":"Low|Medium|High"}]} and nothing else.',
        ]);

        try {
            $raw = $this->ai->complete(
                "Facts:\n".json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                ['system' => $system, 'max_tokens' => 700, 'temperature' => 0.5]
            );
        } catch (AIException $e) {
            throw new BusinessException('AI is unavailable: '.$e->getMessage(), 503);
        }

        $items = $this->parseItems($raw);
        if (empty($items)) {
            Log::channel('tpv')->warning('AI agenda suggestion returned no items', ['raw' => mb_substr($raw, 0, 500)]);
        }

        return [
            'items' => $items,
            'meta' => [
                'provider' => $this->ai->name(),
                'model' => $this->ai->model(),
                'generated_at' => now()->toIso8601String(),
                'facts' => $facts,
            ],
        ];
    }

    /**
     * A concise narrative summary of a completed meeting's minutes, from its
     * decisions, actions and issues. Read-only; never mutates the meeting.
     */
    public function summariseMinutes(KickoffMeeting $meeting): array
    {
        $meeting->loadMissing(['momItems', 'decisions', 'issues']);

        $facts = [
            'title' => $meeting->title,
            'type' => $meeting->meeting_type_label,
            'decisions' => $meeting->decisions->map(fn ($d) => $d->decision)->all(),
            'actions' => $meeting->momItems->map(fn ($a) => trim(strip_tags((string) $a->description))
                .($a->responsible_names ? ' — '.$a->responsible_names : ''))->all(),
            'issues' => $meeting->issues->map(fn ($i) => $i->title.' ('.$i->severity.')')->all(),
        ];

        if (empty($facts['decisions']) && empty($facts['actions']) && empty($facts['issues'])) {
            throw new BusinessException('There are no minutes to summarise yet — add decisions, actions or issues first.');
        }

        $system = implode(' ', [
            'You write the executive summary of a vendor meeting for its minutes.',
            'From the FACTS, write 3-5 plain sentences covering what was decided, what actions were assigned and any issues raised.',
            'Use ONLY the facts. Do not invent outcomes, dates or names. No bullet points.',
        ]);

        try {
            $raw = $this->ai->complete(
                "Facts:\n".json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                ['system' => $system, 'max_tokens' => 500, 'temperature' => 0.4]
            );
        } catch (AIException $e) {
            throw new BusinessException('AI is unavailable: '.$e->getMessage(), 503);
        }

        return [
            'summary' => trim($raw),
            'meta' => [
                'provider' => $this->ai->name(),
                'model' => $this->ai->model(),
                'generated_at' => now()->toIso8601String(),
            ],
        ];
    }

    /** Pull the agenda items out of the model's JSON, tolerating fenced output. */
    private function parseItems(string $raw): array
    {
        $json = trim($raw);
        // Strip a ```json … ``` fence if the model wrapped its answer.
        if (str_contains($json, '```')) {
            $json = preg_replace('/^.*?```(?:json)?/s', '', $json);
            $json = preg_replace('/```.*$/s', '', $json);
        }
        // Fall back to the first {...} block if there is leading prose.
        if (! str_starts_with(ltrim($json), '{') && preg_match('/\{.*\}/s', $json, $mm)) {
            $json = $mm[0];
        }

        $data = json_decode(trim((string) $json), true);
        $rows = $data['items'] ?? (is_array($data) ? $data : []);

        return collect(is_array($rows) ? $rows : [])
            ->map(function ($r) {
                $item = trim((string) (is_array($r) ? ($r['item'] ?? '') : $r));
                if ($item === '') {
                    return null;
                }
                $priority = is_array($r) ? ($r['priority'] ?? null) : null;
                $priority = in_array($priority, ['Low', 'Medium', 'High'], true) ? $priority : null;

                return ['item' => mb_substr($item, 0, 255), 'priority' => $priority];
            })
            ->filter()
            ->take(12)
            ->values()
            ->all();
    }
}
