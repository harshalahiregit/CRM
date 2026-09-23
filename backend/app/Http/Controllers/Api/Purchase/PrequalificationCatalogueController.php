<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Support\Purchase\PrequalificationCatalogue;
use Illuminate\Http\Request;

/**
 * The prequalification questionnaire, edited from the admin panel.
 *
 * The questions lived in a PHP config file, so "set the pre-qualification
 * questions" and "set the drop-down pointers" both had the same answer: only a
 * developer could, and only with a deploy. Every drop-down on that form IS a
 * question in the catalogue.
 *
 * Admin-only. The questionnaire decides which vendors qualify to be bought
 * from, so it sits with the other things only an admin may change — not with
 * the per-vendor assessment, which any manager may record.
 */
class PrequalificationCatalogueController extends Controller
{
    use ApiResponse;

    private function guardAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === 'admin', 403,
            'Only an admin can change the prequalification questionnaire.');
    }

    /** The questionnaire as it stands, plus the shipped one to compare against. */
    public function show(Request $request)
    {
        $this->guardAdmin($request);
        $tenantId = $request->user()->tenant_id;

        return $this->success([
            'sections'     => PrequalificationCatalogue::forTenant($tenantId),
            // So the screen can offer "back to the standard questionnaire" and
            // show what that would mean, rather than asking blind.
            'defaults'     => PrequalificationCatalogue::defaults(),
            'customised'   => PrequalificationCatalogue::isCustomised($tenantId),
            'outcomes'     => config('purchase_prequalification.outcomes', []),
        ], 'Prequalification questionnaire retrieved');
    }

    /** Replace the questionnaire. */
    public function update(Request $request)
    {
        $this->guardAdmin($request);

        $data = $request->validate([
            'sections' => ['required', 'array', 'min:1'],
        ], [
            'sections.required' => 'A questionnaire needs at least one section.',
        ]);

        return $this->success([
            'sections'   => PrequalificationCatalogue::save($request->user()->tenant_id, $data['sections']),
            'customised' => true,
        ], 'Prequalification questionnaire saved');
    }

    /**
     * Back to the shipped questionnaire.
     *
     * Vendors already assessed keep their score and outcome — those are stored
     * on the vendor, not recomputed on read. Their answers to questions that no
     * longer exist simply stop being shown.
     */
    public function reset(Request $request)
    {
        $this->guardAdmin($request);

        return $this->success([
            'sections'   => PrequalificationCatalogue::reset($request->user()->tenant_id),
            'customised' => false,
        ], 'Prequalification questionnaire reset');
    }
}
