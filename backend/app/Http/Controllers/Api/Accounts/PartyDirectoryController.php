<?php

namespace App\Http\Controllers\Api\Accounts;

use App\Contracts\ProjectDirectoryContract;
use App\Http\Controllers\Controller;
use App\Models\Accounts\Ledger;
use App\Models\Customer\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Unified payee/payer directory for the cheque forms (and any future
 * party-picker). Aggregates the three party kinds the CRM recognises into one
 * list, each tagged with its `type` and the `id` a cheque should store:
 *
 *   customer → the client record        (party_id = clients.id)
 *   vendor   → a vendor party ledger     (party_id = acc_ledgers.id)
 *   tpv      → a third-party-vendor ledger (party_id = acc_ledgers.id)
 *
 * Two sources feed it, each entry tagged with `source`:
 *   source=client        -> clients.id        (Customer module)
 *   source=ledger        -> acc_ledgers.id    (party ledgers the Bills flow creates)
 *   source=vendor_master -> vendors.id        (Vendor module, owner: Harshal)
 *
 * A vendor row declares `engagements` (e.g. ['purchase','tpv']), so the same
 * company can appear under both Vendors and Third-Party Vendors. Vendor-master
 * rows whose name already has a control ledger are dropped, so a payee is never
 * offered twice.
 *
 * `party_name` on the saved cheque stays the authoritative snapshot; party_type
 * + party_id is a best-effort link that must be read together with `source`,
 * because the id resolves against a different table per source and none of them
 * is enforced by a foreign key.
 */
class PartyDirectoryController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $search = trim((string) $request->query('search', ''));

        $customers = Client::forTenant($tenantId)
            ->when($search !== '', fn ($q) => $q->where('company', 'like', "%{$search}%"))
            ->orderBy('company')->limit(500)
            ->get(['id', 'company'])
            ->map(fn ($c) => ['type' => 'customer', 'id' => $c->id, 'name' => $c->company]);

        // Accounting-side parties: ledgers auto-created by the Bills flow.
        $partyLedgers = Ledger::forTenant($tenantId)->where('is_party', true)
            ->whereIn('party_type', ['vendor', 'tpv'])
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('name')->limit(500)
            ->get(['id', 'name', 'party_type'])
            ->map(fn ($l) => ['type' => $l->party_type, 'id' => $l->id, 'name' => $l->name, 'source' => 'ledger']);

        // Vendor master (owner: Harshal). `engagements` is a JSON array such as
        // ['purchase','tpv'], so one vendor row can be both a supplier and a
        // third-party vendor — it is listed under each engagement it declares.
        // Guarded by hasTable so this degrades to ledger-only if that module is
        // absent, and deduped by name so a vendor that also has a control ledger
        // isn't offered twice.
        $master = collect();
        if (Schema::hasTable('vendors')) {
            $master = DB::table('vendors')->where('tenant_id', $tenantId)
                ->when($search !== '', fn ($q) => $q->where('company_name', 'like', "%{$search}%"))
                ->orderBy('company_name')->limit(500)
                ->get(['id', 'company_name', 'engagements'])
                ->flatMap(function ($v) {
                    $eng = json_decode($v->engagements ?? '[]', true) ?: [];
                    $types = in_array('tpv', $eng, true) ? ['vendor', 'tpv'] : ['vendor'];
                    return array_map(fn ($t) => [
                        'type' => $t, 'id' => $v->id, 'name' => $v->company_name, 'source' => 'vendor_master',
                    ], $types);
                });
        }
        $ledgerNames = $partyLedgers->map(fn ($l) => strtolower($l['name']))->all();
        $master = $master->reject(fn ($m) => in_array(strtolower($m['name']), $ledgerNames, true));

        // Grouped for the UI's optgroups. `party_name` remains the durable
        // snapshot on the saved record; type+id is a best-effort link qualified
        // by `source`.
        $all = $customers->map(fn ($c) => $c + ['source' => 'client'])
            ->concat($partyLedgers)->concat($master)->values();

        return response()->json([
            'customers' => $all->where('type', 'customer')->values(),
            'vendors'   => $all->where('type', 'vendor')->values(),
            'tpv'       => $all->where('type', 'tpv')->values(),
        ]);
    }

    /**
     * Projects directory — now backed by the real Projects module through
     * ProjectDirectoryContract (it returned an empty stub while that module
     * didn't exist). Feeds the cheque/bill "Project" picker.
     */
    public function projects(Request $request, ProjectDirectoryContract $projects)
    {
        return response()->json($projects->listProjects(
            $request->user()->tenant_id,
            $request->filled('customer_id') ? (int) $request->query('customer_id') : null,
        ));
    }
}
