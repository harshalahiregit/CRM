<?php

namespace Sire\Services;

use Sire\Exceptions\SireException;
use Sire\Models\Release;
use Sire\Models\ReleaseNote;
use Sire\Models\Report;
use Sire\Dto\SireUserIdentity;
use Sire\Support\SireReleaseClass;
use Sire\Support\SireStatus;
use Sire\Support\SireTrack;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SIRE — release notes generated from structured issue data.
 *
 * "Structured data first" is the whole design. Entries are built from columns —
 * track, category, module, user_facing_summary — not from parsing anyone's prose.
 * Free text is used only where a human deliberately wrote it for this purpose
 * (`user_facing_summary`), and falls back to the title when they did not.
 *
 * Two audiences, two different documents:
 *
 *   INTERNAL  what engineering needs: issue numbers, modules, assignees, fix
 *             summaries, root-cause categories, regression flags.
 *   USER      what a customer should be told: one line per user-visible change,
 *             no issue numbers, no internal titles, no blame.
 *
 * An internal title like "null deref in LeadPolicy::view" is not a release note.
 * That is why user_facing_summary exists and why an issue with none is EXCLUDED
 * from the user-facing document rather than leaked into it.
 */
class SireReleaseNotesService
{
    /**
     * Sections follow the release-content taxonomy the brief names: bugs,
     * changes, improvements, security fixes, performance fixes.
     *
     * Security is first because it is why people read release notes at all.
     */
    private const SECTION_ORDER = SireReleaseClass::NOTE_ORDER;

    public function __construct(
        private readonly SireAccessService $access,
        private readonly SireNotifier $notifier,
    ) {
    }

    /**
     * Build (or rebuild) the note for one release and audience.
     *
     * Regeneration is REFUSED once published: a published note must keep saying
     * what it said when it was approved. Reopening an issue afterwards must not
     * silently rewrite something customers have already read.
     */
    public function generate(Release $release, string $audience, SireUserIdentity $actor): ReleaseNote
    {
        if (! in_array($audience, ReleaseNote::AUDIENCES, true)) {
            throw new SireException('Unknown release-note audience.');
        }

        $note = ReleaseNote::query()
            ->forTenant($release->tenant_id)
            ->where('release_id', $release->id)
            ->where('audience', $audience)
            ->first();

        if ($note && $note->isFrozen()) {
            throw new SireException(
                'These release notes are published and can no longer be regenerated. '
                .'Create a follow-up note if something needs correcting.',
            );
        }

        $issues = $this->shippedIssues($release);
        $sections = $audience === ReleaseNote::AUDIENCE_USER
            ? $this->buildUserSections($issues)
            : $this->buildInternalSections($issues);

        $countable = collect($sections)
            ->reject(fn (array $s) => ($s['key'] ?? null) === 'excluded')
            ->sum(fn (array $s) => count($s['entries']));

        return DB::transaction(function () use ($note, $release, $audience, $sections, $countable, $actor) {
            $attributes = [
                'title'        => sprintf('%s %s', $release->version, $release->name ? "— {$release->name}" : ''),
                'sections'     => $sections,
                'issue_count'  => $countable,
                'generated_at' => now(),
                // Regenerating a note that was awaiting approval sends it back to
                // draft: nobody should approve one document and publish another.
                'status'       => ReleaseNote::DRAFT,
                'approved_by'  => null,
                'approved_at'  => null,
            ];

            if ($note) {
                $note->fill($attributes)->save();
            } else {
                $note = ReleaseNote::create(array_merge($attributes, [
                    'tenant_id'  => $release->tenant_id,   // explicit, never ambient
                    'release_id' => $release->id,
                    'audience'   => $audience,
                ]));
            }

            $note->recordAudit(
                "Release notes generated ({$audience})",
                $actor,
                null,
                ['action' => 'generate', 'issue_count' => $countable, 'system' => true],
            );

            return $note->fresh();
        });
    }

    /**
     * Issues that actually shipped in this release. Terminal-but-not-fixed
     * outcomes are excluded: a rejected or duplicate issue never shipped anything.
     */
    private function shippedIssues(Release $release): Collection
    {
        return Report::query()
            ->forTenant($release->tenant_id)
            ->where('released_version_id', $release->id)
            ->whereNotIn('status', [SireStatus::DUPLICATE, SireStatus::REJECTED, SireStatus::WONT_FIX])
            ->with(['category:id,code,name,release_class', 'severity:id,code,name', 'assignee:id,name', 'rootCause:id,report_id,category'])
            ->orderBy('module')
            ->orderBy('report_number')
            ->get();
    }

    private function buildUserSections(Collection $issues): array
    {
        $buckets = [];
        $securityCount = 0;

        foreach ($issues as $issue) {
            if (! $issue->include_in_release_notes) {
                continue; // deliberately internal-only
            }

            $class = $issue->releaseClass();

            /*
             * SECURITY FIXES ARE COUNTED, NOT DESCRIBED.
             *
             * "Fixed an unauthenticated file read in the attachment download
             * endpoint" is a working exploit for every customer who has not
             * patched yet. The note says how many security fixes shipped and
             * advises updating; the detail stays in the internal document.
             */
            if (in_array($class, SireReleaseClass::REDACTED_FOR_USERS, true)) {
                $securityCount++;

                continue;
            }

            $summary = trim((string) $issue->user_facing_summary);
            if ($summary === '') {
                // No customer-facing wording was written, so there is nothing safe
                // to publish. Substituting the internal title is how "null deref in
                // LeadPolicy::view" reaches a customer.
                continue;
            }

            $buckets[$class][] = [
                'summary' => $summary,
                'module'  => $issue->module_label ?? $issue->module,
            ];
        }

        $sections = $this->toSections($buckets);

        if ($securityCount > 0) {
            array_unshift($sections, [
                'key'      => SireReleaseClass::SECURITY,
                'label'    => SireReleaseClass::label(SireReleaseClass::SECURITY),
                'redacted' => true,
                'entries'  => [[
                    'summary' => $securityCount === 1
                        ? 'This release contains a security fix. We recommend updating promptly.'
                        : "This release contains {$securityCount} security fixes. We recommend updating promptly.",
                ]],
            ]);
        }

        return $sections;
    }

    private function buildInternalSections(Collection $issues): array
    {
        $buckets = [];
        $excluded = [];

        foreach ($issues as $issue) {
            $entry = [
                'number'      => $issue->report_number,
                'title'       => $issue->title,
                'module'      => $issue->module_label ?? $issue->module,
                'type'        => $issue->category?->name,
                'severity'    => $issue->severity?->name,
                'priority'    => $issue->priority,
                'assignee'    => $issue->assignee?->name,
                'fix_summary' => $issue->fix_summary,
                'root_cause'  => $issue->rootCause?->category,
                'regression'  => (bool) $issue->is_regression,
                'class'       => $issue->releaseClass(),
            ];

            if (! $issue->include_in_release_notes) {
                $excluded[] = $entry;

                continue;
            }

            $buckets[$issue->releaseClass()][] = $entry;
        }

        $sections = $this->toSections($buckets);

        if ($excluded !== []) {
            // Shipped but not announced. Present in the internal document on
            // purpose: "what went out" and "what we told anyone" are different
            // questions, and the gap between them is worth being able to see.
            $sections[] = ['key' => 'excluded', 'label' => 'Shipped but not announced', 'entries' => $excluded];
        }

        return $sections;
    }

    /** Ordered by SECTION_ORDER; empty sections dropped — a bare heading reads as an omission. */
    private function toSections(array $buckets): array
    {
        $sections = [];

        foreach (self::SECTION_ORDER as $class) {
            if (empty($buckets[$class])) {
                continue;
            }
            $sections[] = [
                'key'     => $class,
                'label'   => SireReleaseClass::label($class),
                'entries' => $buckets[$class],
            ];
        }

        return $sections;
    }

    // ------------------------------------------------------- approval & publish

    /**
     * Publication requires authorised approval. Two separate steps on purpose:
     * approving says the content is right, publishing says it is time. The same
     * person may do both, but the record shows which happened when.
     */
    public function requestApproval(ReleaseNote $note, SireUserIdentity $actor): ReleaseNote
    {
        if ($note->status !== ReleaseNote::DRAFT) {
            throw new SireException('Only a draft can be sent for approval.');
        }
        if (empty($note->sections)) {
            throw new SireException('There is nothing to approve — generate the notes first.');
        }

        $note->status = ReleaseNote::PENDING_APPROVAL;
        $note->save();
        $note->recordAudit('Release notes sent for approval', $actor, null, ['system' => true]);

        return $note;
    }

    public function approve(ReleaseNote $note, SireUserIdentity $actor): ReleaseNote
    {
        $this->access->assert($actor, 'sire.release_notes.approve');

        if ($note->status !== ReleaseNote::PENDING_APPROVAL) {
            throw new SireException('These notes are not awaiting approval.');
        }

        $note->status = ReleaseNote::APPROVED;
        $note->approved_by = $actor->id;
        $note->approved_at = now();
        $note->save();
        $note->recordAudit('Release notes approved', $actor, null, ['system' => true]);

        return $note;
    }

    public function publish(ReleaseNote $note, SireUserIdentity $actor): ReleaseNote
    {
        $this->access->assert($actor, 'sire.release_notes.publish');

        // The gate the brief asks for, stated as a rule rather than a hope:
        // nothing reaches an audience without an approval on the record.
        if ($note->status !== ReleaseNote::APPROVED) {
            throw new SireException('These notes must be approved before they can be published.');
        }

        $note->status = ReleaseNote::PUBLISHED;
        $note->published_by = $actor->id;
        $note->published_at = now();
        $note->save();
        $note->recordAudit('Release notes published', $actor, null, ['system' => true]);

        return $note;
    }
}
