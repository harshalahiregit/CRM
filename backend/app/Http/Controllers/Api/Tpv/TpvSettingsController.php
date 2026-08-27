<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Models\Tpv\TpvSetting;
use App\Support\Tpv\TpvSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * TPV System Configuration (Sangoe TPV §34) — the admin surface that makes the
 * governance engines tenant-configurable. Reads return {builtins, custom,
 * effective} per group so the UI can show what a saved override changes; writes
 * upsert a single group's JSON document and drop the catalog memo so the next
 * request sees the change. Deleting a group's override reverts it to the shipped
 * defaults. All writes are admin-only.
 */
class TpvSettingsController extends Controller
{
    public function __construct(private TpvSettings $settings) {}

    /** Everything the settings screen needs, in one call. */
    public function index(Request $request)
    {
        return response()->json(['data' => $this->settings->bundle($request->user()->tenant_id)]);
    }

    /** Save (upsert) one group's override. */
    public function update(Request $request, string $group)
    {
        $this->assertAdmin($request);
        abort_unless(in_array($group, TpvSetting::GROUPS, true), 404, 'Unknown settings group.');

        $payload = $this->validateGroup($request, $group);

        TpvSetting::updateOrCreate(
            ['tenant_id' => $request->user()->tenant_id, 'group' => $group],
            ['payload' => $payload, 'updated_by' => $request->user()->id],
        );
        $this->settings->forget($request->user()->tenant_id, $group);

        return response()->json([
            'message' => 'Settings saved.',
            'data'    => [
                'builtins'  => $this->settings->baseline($group),
                'custom'    => $payload,
                'effective' => $this->settings->effective($group, $request->user()->tenant_id),
            ],
        ]);
    }

    /** Revert one group to the shipped defaults (delete the override). */
    public function reset(Request $request, string $group)
    {
        $this->assertAdmin($request);
        abort_unless(in_array($group, TpvSetting::GROUPS, true), 404, 'Unknown settings group.');

        TpvSetting::query()
            ->forTenant($request->user()->tenant_id)
            ->where('group', $group)
            ->delete();
        $this->settings->forget($request->user()->tenant_id, $group);

        return response()->json([
            'message' => 'Reverted to defaults.',
            'data'    => [
                'builtins'  => $this->settings->baseline($group),
                'custom'    => null,
                'effective' => $this->settings->effective($group, $request->user()->tenant_id),
            ],
        ]);
    }

    /* ── Per-group validation ───────────────────────────────────────────── */

    private function validateGroup(Request $request, string $group): array
    {
        return match ($group) {
            'strike_rules' => $request->validate([
                'limit'                           => 'required|integer|min:1|max:50',
                'warn_at'                         => 'required|integer|min:1|lte:limit',
                'critical_terminates_immediately' => 'required|boolean',
            ]),
            'vpi' => $this->validateVpi($request),
            'approval_workflow' => $request->validate([
                'mode'              => ['required', Rule::in(['single', 'multi_level'])],
                'levels'            => 'required|array|min:1',
                'levels.*.level'    => 'required|integer|min:1',
                'levels.*.role'     => 'required|string|max:40',
                'levels.*.label'    => 'required|string|max:80',
                'sla_hours'         => 'required|integer|min:1|max:8760',
            ]),
            'authority_matrix' => $request->validate([
                'authorities'                 => 'required|array|min:1',
                'authorities.*.label'         => 'required|string|max:120',
                'authorities.*.responsibilities' => 'nullable|array',
                'matrix'                      => 'required|array',
                'matrix.*.action'             => 'required|string|max:150',
                'matrix.*.gate'               => 'nullable|string|max:80',
                'matrix.*.authorities'        => 'required|array',
            ]),
            'approval_types' => $request->validate([
                'types'              => 'required|array|min:1',
                'types.*.value'      => 'required|string|max:60',
                'types.*.label'      => 'required|string|max:120',
                'types.*.is_active'  => 'required|boolean',
            ]),
            'gate' => $request->validate([
                'ppe_enforcement' => ['required', Rule::in(['warn', 'deny', 'off'])],
            ]),
            'violation_ladder' => $this->validateViolationLadder($request),
            'onboarding_checklists' => $request->validate([
                'dimensions'          => 'nullable|array',
                'dimensions.*'        => 'string|max:40',
                'rules'               => 'present|array',
                'rules.*.match'       => 'required|array|min:1',
                'rules.*.items'       => 'required|array|min:1',
                'rules.*.items.*'     => 'string|max:200',
                'general'             => 'required|array',
                'general.gates_activation' => 'required|boolean',
                'general.items'       => 'required|array',
                'general.items.*'     => 'string|max:200',
            ]),
            'approval_routing' => $request->validate([
                'dimensions'        => 'nullable|array',
                'dimensions.*'      => 'string|max:40',
                'rules'             => 'present|array',
                'rules.*.match'     => 'required|array|min:1',
                'rules.*.levels'    => 'required|array|min:1',
                'rules.*.levels.*'  => 'string|max:40',
                'default_levels'    => 'required|array|min:1',
                'default_levels.*'  => 'string|max:40',
            ]),
            'catalogs' => $request->validate([
                'vendor_types'            => 'sometimes|array',
                'vendor_types.*'          => 'string|max:80',
                'vendor_categories'       => 'sometimes|array',
                'vendor_categories.*'     => 'string|max:80',
                'vendor_classes'          => 'sometimes|array',
                'vendor_classes.*'        => 'string|max:80',
                'risk_levels'             => 'sometimes|array',
                'risk_levels.*'           => 'string|max:80',
                'document_types'          => 'sometimes|array',
                'document_types.*'        => 'string|max:80',
                'training_types'          => 'sometimes|array',
                'training_types.*'        => 'string|max:80',
                'competency_requirements' => 'sometimes|array',
                'competency_requirements.*' => 'string|max:80',
                'permit_types'            => 'sometimes|array',
                'permit_types.*'          => 'string|max:80',
                'violation_types'         => 'sometimes|array',
                'violation_types.*'       => 'string|max:80',
                'compliance_categories'   => 'sometimes|array',
                'compliance_categories.*' => 'string|max:80',
            ]),
            default => abort(404, 'Unknown settings group.'),
        };
    }

    private function validateViolationLadder(Request $request): array
    {
        $data = $request->validate([
            'severity_points'          => 'required|array|min:1',
            'severity_points.*'        => 'required|integer|min:0|max:100',
            'steps'                    => 'required|array|min:1',
            'steps.*.points'           => 'required|integer|min:0|max:1000',
            'steps.*.level'            => 'required|string|max:40',
            // §26 — optional per-project overrides { project: { severity_points?, steps? } }.
            'project_overrides'                    => 'sometimes|array',
            'project_overrides.*.severity_points'  => 'sometimes|array',
            'project_overrides.*.steps'            => 'sometimes|array',
            'project_overrides.*.steps.*.points'   => 'required_with:project_overrides.*.steps|integer|min:0|max:1000',
            'project_overrides.*.steps.*.level'    => 'required_with:project_overrides.*.steps|string|max:40',
        ]);

        // A ladder has to start at zero, or a vendor with no points has no level.
        $minPoints = min(array_map(fn ($s) => (int) $s['points'], $data['steps']));
        abort_if($minPoints !== 0, 422, 'The ladder must include a step at 0 points (the baseline level).');

        return $data;
    }

    private function validateVpi(Request $request): array
    {
        $data = $request->validate([
            'weights'                  => 'required|array|min:1',
            'weights.*'                => 'required|numeric|min:0|max:1',
            'deductions'               => 'required|array|min:1',
            'deductions.*'             => 'required|numeric|min:0|max:100',
            'doc_expiring_window_days' => 'required|integer|min:1|max:365',
            'bands'                    => 'required|array',
            'bands.A'                  => 'required|integer|min:0|max:100',
            'bands.B'                  => 'required|integer|min:0|max:100',
            'bands.C'                  => 'required|integer|min:0|max:100',
            'bands.D'                  => 'required|integer|min:0|max:100',
        ]);

        // Weights should sum to ~1.0 — the index is a weighted average. Guard it
        // so a fat-fingered weight can't silently skew every vendor's score.
        $sum = array_sum($data['weights']);
        abort_if(abs($sum - 1.0) > 0.001, 422, 'Dimension weights must sum to 1.00 (currently '.round($sum, 3).').');

        return $data;
    }

    private function assertAdmin(Request $request): void
    {
        abort_unless($request->user()->role === 'admin', 403, 'Only an admin can change TPV settings.');
    }
}
