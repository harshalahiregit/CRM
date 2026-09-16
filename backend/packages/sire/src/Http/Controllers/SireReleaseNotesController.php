<?php

namespace Sire\Http\Controllers;

use Sire\Http\Controllers\Concerns\AssertsSireTenantOwnership;
use Sire\Http\Controllers\Concerns\ResolvesSireUser;
use Sire\Http\Controllers\Concerns\SireApiResponse;
use Sire\Models\Release;
use Sire\Models\ReleaseNote;
use Sire\Services\SireReleaseNotesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Generate → request approval → approve → publish.
 *
 * Four steps, not one button, because publication is the step that reaches an
 * audience and the brief requires an authorised approval before it does.
 */
class SireReleaseNotesController extends SireController
{
    use SireApiResponse;
    use ResolvesSireUser;
    use AssertsSireTenantOwnership;

    public function __construct(private readonly SireReleaseNotesService $notes)
    {
    }

    public function show(Request $request, ReleaseNote $note): JsonResponse
    {
        $this->assertTenantOwnership($note);

        return $this->success($note->load('release:id,version,name,release_date', 'approver:id,name', 'publisher:id,name'));
    }

    public function generate(Request $request, Release $release): JsonResponse
    {
        $this->assertTenantOwnership($release);

        $audience = $request->validate([
            'audience' => ['required', Rule::in(ReleaseNote::AUDIENCES)],
        ])['audience'];

        return $this->success($this->notes->generate($release, $audience, $this->sireUser()));
    }

    /**
     * Regenerate in place. The note already knows its release and audience, so
     * the caller does not restate them — and cannot accidentally regenerate a
     * user-facing document from the internal one's parameters.
     */
    public function regenerate(Request $request, ReleaseNote $note): JsonResponse
    {
        $this->assertTenantOwnership($note);

        return $this->success(
            $this->notes->generate($note->release, $note->audience, $this->sireUser()),
        );
    }

    /** Human edits sit alongside the generated structure, never replacing it. */
    public function update(Request $request, ReleaseNote $note): JsonResponse
    {
        $this->assertTenantOwnership($note);

        $data = $request->validate([
            'title'         => ['nullable', 'string', 'max:255'],
            'body_override' => ['nullable', 'string', 'max:100000'],
        ]);

        if ($note->isFrozen()) {
            return $this->error('Published release notes cannot be edited.', 422);
        }

        $note->fill($data)->save();

        return $this->success($note);
    }

    public function requestApproval(Request $request, ReleaseNote $note): JsonResponse
    {
        $this->assertTenantOwnership($note);

        return $this->success($this->notes->requestApproval($note, $this->sireUser()));
    }

    public function approve(Request $request, ReleaseNote $note): JsonResponse
    {
        $this->assertTenantOwnership($note);

        return $this->success($this->notes->approve($note, $this->sireUser()));
    }

    public function publish(Request $request, ReleaseNote $note): JsonResponse
    {
        $this->assertTenantOwnership($note);

        return $this->success($this->notes->publish($note, $this->sireUser()));
    }
}
