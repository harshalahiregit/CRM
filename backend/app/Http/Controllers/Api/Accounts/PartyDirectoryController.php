<?php

namespace App\Http\Controllers\Api\Accounts;

use App\Http\Controllers\Controller;
use App\Models\Accounts\Ledger;
use App\Models\Customer\Client;
use Illuminate\Http\Request;

/**
 * Unified payee/payer directory for the cheque forms (and any future
 * party-picker). Aggregates the three party kinds the CRM recognises into one
 * list, each tagged with its `type` and the `id` a cheque should store:
 *
 *   customer → the client record        (party_id = clients.id)
 *   vendor   → a vendor party ledger     (party_id = acc_ledgers.id)
 *   tpv      → a third-party-vendor ledger (party_id = acc_ledgers.id)
 *
 * Customers exist today (Customer module). Vendors already exist as party
 * ledgers auto-created by the Bills flow. The dedicated Vendor and Third-Party
 * Vendor modules aren't built yet — when they are, they populate party ledgers
 * (or their own tables) and simply feed this same endpoint, so the cheque form
 * needs no further change. TPV therefore returns whatever party ledgers already
 * carry party_type = 'tpv' (empty until that module creates them).
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

        $partyLedgers = Ledger::forTenant($tenantId)->where('is_party', true)
            ->whereIn('party_type', ['vendor', 'tpv'])
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('name')->limit(500)
            ->get(['id', 'name', 'party_type'])
            ->map(fn ($l) => ['type' => $l->party_type, 'id' => $l->id, 'name' => $l->name]);

        // Grouped for the UI's optgroups, plus a flat list for convenience.
        $all = $customers->concat($partyLedgers)->values();

        return response()->json([
            'customers' => $all->where('type', 'customer')->values(),
            'vendors'   => $all->where('type', 'vendor')->values(),
            'tpv'       => $all->where('type', 'tpv')->values(),
        ]);
    }

    /**
     * Projects directory — seam for the future Projects module. Honest empty
     * list today so the cheque form's Project picker is fully wired for the day
     * that module ships (it will replace this stub's body).
     */
    public function projects(Request $request)
    {
        return response()->json([]);
    }
}
