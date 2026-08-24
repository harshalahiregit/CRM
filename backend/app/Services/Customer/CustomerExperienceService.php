<?php

namespace App\Services\Customer;

use App\Models\Customer\Client;
use App\Models\Customer\ClientFeedback;
use App\Models\Customer\ClientComplaint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * §10 — Customer Experience.
 *
 * CSAT and NPS are not two versions of the same number and must not be averaged
 * together. CSAT is the mean of per-interaction ratings on a five-point scale.
 * NPS is the standard net score: the share of promoters (9-10) minus the share
 * of detractors (0-6), which runs from -100 to +100 and is not a percentage of
 * anything.
 *
 * Both are also reported normalised to 0-100 so Customer Health (§8) has one
 * comparable figure to score without having to know either scale.
 */
class CustomerExperienceService
{
    /** Below this, a score says more about the sample than the customer. */
    public const MIN_RESPONSES = 2;

    public function forClient(Client $client): array
    {
        return $this->summarise(
            ClientFeedback::forTenant($client->tenant_id)->where('client_id', $client->id)->get()
        ) + [
            'complaints'     => $this->complaints($client),
            'resolution'     => $this->resolution($client),
            'service_quality' => $this->serviceQuality($client),
        ];
    }

    /**
     * §10 — complaints and escalations, summarised where the rest of Customer
     * Experience lives.
     *
     * They have their own tab for working through; this is the headline a
     * manager needs beside the satisfaction scores, because a 4.5 CSAT next to
     * two open escalations tells a different story from a 4.5 on its own.
     */
    private function complaints(Client $client): array
    {
        if (! Schema::hasTable('client_complaints')) {
            return ['total' => 0, 'open' => 0, 'escalations' => 0, 'severe' => 0, 'last_raised' => null];
        }

        $rows = ClientComplaint::forTenant($client->tenant_id)
            ->where('client_id', $client->id)
            ->get(['kind', 'severity', 'status', 'raised_at']);

        return [
            'total'       => $rows->count(),
            'open'        => $rows->whereIn('status', ClientComplaint::OPEN_STATUSES)->count(),
            'escalations' => $rows->where('kind', 'Escalation')->count(),
            'severe'      => $rows->whereIn('severity', ['High', 'Critical'])->count(),
            'last_raised' => $rows->max('raised_at')?->toIso8601String(),
        ];
    }

    /**
     * §10 — resolution time, from both sides of it.
     *
     * Tickets measure day-to-day responsiveness; complaints measure how long
     * something that went properly wrong took to put right. They are different
     * questions and averaging them together would hide the second behind the
     * volume of the first.
     *
     * SWAP POINT — tickets belongs to Helpdesk. Read-only, guarded, and it must
     * exclude soft-deleted rows or a withdrawn ticket keeps counting.
     */
    private function resolution(Client $client): array
    {
        $tickets = null;

        if (Schema::hasTable('tickets') && Schema::hasColumn('tickets', 'resolved_at')) {
            $rows = DB::table('tickets')
                ->whereNull('deleted_at')
                ->where('tenant_id', $client->tenant_id)
                ->where('customer_id', $client->id)
                ->whereNotNull('resolved_at')->whereNotNull('created_at')
                ->get(['created_at', 'resolved_at']);

            if ($rows->isNotEmpty()) {
                $tickets = [
                    'average_hours' => round($rows->avg(fn ($t) =>
                        max(0, (strtotime($t->resolved_at) - strtotime($t->created_at)) / 3600)), 1),
                    'resolved'      => $rows->count(),
                ];
            }
        }

        $complaints = null;

        if (Schema::hasTable('client_complaints')) {
            $done = ClientComplaint::forTenant($client->tenant_id)
                ->where('client_id', $client->id)
                ->whereNotNull('resolved_at')
                ->get();

            if ($done->isNotEmpty()) {
                $complaints = [
                    'average_hours' => round($done->avg(fn ($c) => $c->resolution_hours ?? 0), 1),
                    'resolved'      => $done->count(),
                    'still_open'    => ClientComplaint::forTenant($client->tenant_id)
                        ->where('client_id', $client->id)->open()->count(),
                ];
            }
        }

        return ['tickets' => $tickets, 'complaints' => $complaints];
    }

    /**
     * §10 — service quality, as one number with its inputs on show.
     *
     * Deliberately NOT a new formula. It reuses the two parameters Customer
     * Health already scores — service performance (resolution speed) and
     * complaint frequency — so the Experience tab and the Health panel cannot
     * quietly disagree about the same customer. A second opinion computed a
     * second way is how two screens end up arguing.
     *
     * Null when neither input is measurable: "we have not measured this" and
     * "this is poor" are different answers.
     */
    private function serviceQuality(Client $client): ?array
    {
        $health = app(CustomerHealthService::class)->score($client);
        $by     = collect($health['breakdown'])->keyBy('key');

        $parts = collect(['service_performance', 'complaint_frequency'])
            ->map(fn ($k) => $by->get($k))
            ->filter(fn ($p) => $p && $p['available']);

        if ($parts->isEmpty()) {
            return null;
        }

        $score = round($parts->avg('score'), 1);

        return [
            'score'  => $score,
            'band'   => match (true) {
                $score >= 85 => 'Good',
                $score >= 60 => 'Fair',
                default      => 'Poor',
            },
            // The inputs, so the number is auditable rather than asserted.
            'inputs' => $parts->map(fn ($p) => [
                'label'  => $p['label'],
                'score'  => $p['score'],
                'detail' => $p['detail'],
            ])->values()->all(),
        ];
    }

    public function summarise(Collection $rows): array
    {
        $csat = $rows->where('metric', ClientFeedback::CSAT);
        $nps  = $rows->where('metric', ClientFeedback::NPS);

        return [
            'csat'          => $this->csat($csat),
            'nps'           => $this->nps($nps),
            'responses'     => $rows->count(),
            'last_response' => $rows->max('responded_at')?->toIso8601String(),
        ];
    }

    private function csat(Collection $rows): ?array
    {
        if ($rows->isEmpty()) {
            return null;
        }

        $avg = round($rows->avg('score'), 2);

        return [
            'average'    => $avg,           // out of 5
            'out_of'     => ClientFeedback::MAX[ClientFeedback::CSAT],
            'percent'    => round(($avg / ClientFeedback::MAX[ClientFeedback::CSAT]) * 100, 1),
            // The industry reading of CSAT: the share who answered 4 or 5.
            'satisfied'  => round(($rows->where('score', '>=', 4)->count() / $rows->count()) * 100, 1),
            'responses'  => $rows->count(),
            'provisional' => $rows->count() < self::MIN_RESPONSES,
        ];
    }

    private function nps(Collection $rows): ?array
    {
        if ($rows->isEmpty()) {
            return null;
        }

        $total     = $rows->count();
        $promoters = $rows->where('score', '>=', 9)->count();
        $passives  = $rows->whereBetween('score', [7, 8])->count();
        $detractors = $total - $promoters - $passives;

        $score = round((($promoters - $detractors) / $total) * 100);

        return [
            'score'      => $score,          // -100 … +100
            'promoters'  => $promoters,
            'passives'   => $passives,
            'detractors' => $detractors,
            'responses'  => $total,
            // NPS maps to 0-100 by shifting the midpoint, NOT by clamping:
            // a -100 and a 0 are genuinely different and must not both read 0.
            'normalised' => round(($score + 100) / 2, 1),
            'band'       => match (true) {
                $score >= 50 => 'Excellent',
                $score >= 0  => 'Good',
                $score >= -50 => 'Poor',
                default      => 'Critical',
            },
            'provisional' => $total < self::MIN_RESPONSES,
        ];
    }

    /**
     * One 0-100 figure for Customer Health, or null when nobody has answered.
     *
     * When both metrics exist they are weighted equally. NPS is a relationship
     * measure and CSAT a service one, and the document lists "Customer feedback"
     * as a single Health parameter, so collapsing them here keeps that promise
     * without Health needing to know either scale.
     */
    public function healthSignal(Client $client): ?array
    {
        // Summarise the feedback rows DIRECTLY — do not go through forClient().
        //
        // forClient() also builds complaints, resolution and service_quality,
        // and service_quality calls CustomerHealthService::score(), which calls
        // customerFeedback(), which calls this method. That is an unbounded
        // mutual recursion: it exhausted PHP's 128MB limit and made the whole
        // Customer 360 overview time out with a 504, on a database whose largest
        // table has 428 rows. It was never a volume problem.
        //
        // Only CSAT and NPS are wanted here, so the extra work was wrong on its
        // own terms as well as fatal.
        $summary = $this->summarise(
            ClientFeedback::forTenant($client->tenant_id)->where('client_id', $client->id)->get()
        );
        $parts   = [];

        if ($summary['csat']) {
            $parts['CSAT'] = $summary['csat']['percent'];
        }
        if ($summary['nps']) {
            $parts['NPS'] = $summary['nps']['normalised'];
        }

        if ($parts === []) {
            return null;
        }

        $detail = collect($parts)
            ->map(fn ($v, $k) => $k === 'NPS'
                ? "NPS {$summary['nps']['score']}"
                : "CSAT {$summary['csat']['average']}/5")
            ->implode(' · ');

        return [
            'score'  => round(array_sum($parts) / count($parts), 1),
            'detail' => $detail." across {$summary['responses']} responses",
        ];
    }
}
