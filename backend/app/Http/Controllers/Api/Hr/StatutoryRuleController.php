<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrStatutoryRule;
use App\Rules\Hr\ValidWorkState;
use App\Services\Hr\Statutory\StatutoryRuleService;
use App\Support\Hr\WorkStates;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Payroll → Statutory rule book. Thin: validate, delegate, return JSON.
 *
 * Reads are open to HR users; every write requires HR-queue management, because a
 * rule edit changes what is deducted from people's pay.
 */
class StatutoryRuleController extends Controller
{
    public function __construct(private StatutoryRuleService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json([
            'data' => $this->service->list($this->tenant($request), $request->only(['rule_type', 'state', 'is_active'])),
        ]);
    }

    /**
     * Everything the config screen needs to render itself: the rule types, the
     * state vocabulary, and the company defaults. Served together so the UI holds
     * no copy of any of it.
     */
    public function meta(Request $request)
    {
        $tenantId = $this->tenant($request);

        // States already carrying a PT rule here, so a jurisdiction this
        // workspace is genuinely using never disappears from its own screen
        // because the canonical list has not caught up with a budget.
        $configured = HrStatutoryRule::where('tenant_id', $tenantId)
            ->whereNotNull('state')
            ->distinct()
            ->pluck('state')
            ->all();

        return response()->json([
            'rule_types'  => HrStatutoryRule::TYPES,
            // Every state, for the rules that are not state-levied.
            'work_states' => WorkStates::options(),
            // PT alone. Roughly a third of the country does not levy it, and
            // offering those invites a slab that would then deduct — Delhi sat
            // in this list with nothing to say it collects nothing. The list is
            // a SETTING, seeded from WorkStates::PT_APPLICABLE, because a state
            // can begin or repeal the levy in a budget and the people who would
            // notice are the ones running payroll.
            'pt_states'   => WorkStates::ptSelectable(
                (array) app(\App\Services\Settings\SettingsService::class)
                    ->get($tenantId, 'payroll', 'pt_states', WorkStates::PT_APPLICABLE),
                $configured
            ),
            // Offered so the settings screen can present the full vocabulary to
            // choose from, rather than the UI holding its own copy.
            'all_states'  => WorkStates::options(),
            'defaults'    => $this->service->defaults($tenantId),
        ]);
    }

    public function store(Request $request)
    {
        $this->assertCanManage($request);

        return response()->json(
            $this->service->create($this->validated($request), $this->tenant($request), $request->user()),
            201
        );
    }

    public function update(Request $request, int $id)
    {
        $this->assertCanManage($request);

        return response()->json(
            $this->service->update($id, $this->validated($request, partial: true), $this->tenant($request), $request->user())
        );
    }

    public function destroy(Request $request, int $id)
    {
        $this->assertCanManage($request);
        $this->service->destroy($id, $this->tenant($request), $request->user());

        return response()->json(['message' => 'Deleted']);
    }

    public function saveDefaults(Request $request)
    {
        $this->assertCanManage($request);
        $data = $request->validate([
            'default_work_state'       => ['nullable', 'string', 'max:80', new ValidWorkState],
            'fy_start_month'           => 'nullable|integer|min:1|max:12',
            'loan_emi_warn_percent'    => 'nullable|numeric|min:0|max:100',
            'loan_emi_max_percent'     => 'nullable|numeric|min:0|max:100',
            'loan_enforce_eligibility' => 'nullable|boolean',
            // Which states offer PT. Codes, validated against the vocabulary so
            // a typo cannot put a state in the list that no rule can match.
            'pt_states'                => 'nullable|array',
            'pt_states.*'              => ['string', Rule::in(array_keys(WorkStates::ALL))],
            // What holds up a run, who may approve it, whether probation gates
            // leave — judgements about the business, so they belong here rather
            // than in PHP.
            'require_bank_for_payroll'       => 'nullable|boolean',
            'require_pan_for_payroll'        => 'nullable|boolean',
            'require_aadhaar_for_payroll'    => 'nullable|boolean',
            'require_work_state_for_payroll' => 'nullable|boolean',
            'require_separate_approver'      => 'nullable|boolean',
            'probation_blocks_leave'         => 'nullable|boolean',
        ]);

        return response()->json($this->service->saveDefaults($data, $this->tenant($request)));
    }

    /**
     * `config` is validated as a free-form array on purpose — its shape differs per
     * rule type and its VALUES are legal figures this application must not second
     * guess. Structural checks live in the service.
     */
    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'rule_type'      => [$partial ? 'sometimes' : 'required', Rule::in(HrStatutoryRule::TYPES)],
            'state'          => ['nullable', 'string', 'max:80', new ValidWorkState],
            'effective_from' => "{$req}|date",
            'effective_to'   => 'nullable|date',
            'config'         => ($partial ? 'sometimes' : 'required').'|array',
            'is_active'      => 'nullable|boolean',
            'notes'          => 'nullable|string|max:1000',
        ]);
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function assertCanManage(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage statutory rules');
    }
}
