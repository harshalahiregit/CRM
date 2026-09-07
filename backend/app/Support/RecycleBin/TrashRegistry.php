<?php

namespace App\Support\RecycleBin;

use Illuminate\Database\Eloquent\Model;

/**
 * What the recycle bin can bring back.
 *
 * Deleting is the one action in this CRM with no undo, and roughly two hundred
 * endpoints offer it. Almost every record already soft-deletes — the rows were
 * always still there — but only five types were ever reachable again, so for
 * everything else "recoverable in the database" and "recoverable by the person
 * who deleted it" were not the same thing.
 *
 * Rather than a trash screen per module, this is one registry and one screen.
 * Adding a type is a line here: the model, the columns that name a row to a
 * human, and the group it files under.
 *
 * ── What is deliberately NOT here ───────────────────────────────────────────
 * Not everything that soft-deletes belongs in a bin a person reads. Line items,
 * document versions, gate scans, activity and audit rows are soft-deleted for
 * referential safety, not because anyone would go looking for one; listing them
 * would bury the invoice somebody actually needs among thousands of fragments.
 * Pure link rows (purchase_vendor_items, tpv_vendor_projects) are excluded for
 * a second reason too — they carry no column that could name them on screen.
 *
 * The registry is asserted against the real schema by RecycleBinRegistryTest,
 * so a typo here fails a test rather than 500ing the first time somebody
 * deletes one of these.
 */
class TrashRegistry
{
    /**
     * key => [model, label columns (in order, joined with ·), type label, group].
     *
     * The first five keys are the ones this bin already used; they keep their
     * names so existing links and any saved filter still resolve.
     */
    private const TYPES = [
        // ── Sales ───────────────────────────────────────────────────────────
        'invoice'          => [\App\Models\Sales\SalesInvoice::class,     ['number'],                      'Tax Invoice',         'Sales'],
        'estimate'         => [\App\Models\Sales\Estimate::class,         ['reference'],                   'Estimate / Proforma', 'Sales'],
        'proposal'         => [\App\Models\Sales\Proposal::class,         ['reference_no', 'subject'],     'Proposal',            'Sales'],
        'credit_note'      => [\App\Models\Sales\CreditNote::class,       ['number'],                      'Credit Note',         'Sales'],
        'delivery_note'    => [\App\Models\Sales\DeliveryNote::class,     ['number'],                      'Delivery Note',       'Sales'],
        'retainer_invoice' => [\App\Models\Sales\RetainerInvoice::class,  ['number'],                      'Retainer Invoice',    'Sales'],
        'sales_contract'   => [\App\Models\Sales\SalesContract::class,    ['reference_no', 'subject'],     'Sales Contract',      'Sales'],
        'lead'             => [\App\Models\Sales\Lead::class,             ['name'],                        'Lead',                'Sales'],
        'appointment'      => [\App\Models\Sales\Appointment::class,      ['title'],                       'Appointment',         'Sales'],
        'sales_item'       => [\App\Models\Sales\SalesItem::class,        ['name'],                        'Sales Item',          'Sales'],

        // ── Customers ───────────────────────────────────────────────────────
        'client'           => [\App\Models\Customer\Client::class,              ['company'],                    'Customer',           'Customers'],
        'client_contact'   => [\App\Models\Customer\ClientContact::class,       ['first_name', 'last_name'],    'Customer Contact',   'Customers'],
        'client_complaint' => [\App\Models\Customer\ClientComplaint::class,     ['reference'],                  'Complaint',          'Customers'],
        'client_po'        => [\App\Models\Customer\ClientPurchaseOrder::class, ['po_number'],                  'Customer PO',        'Customers'],

        // ── Accounts ────────────────────────────────────────────────────────
        'acc_voucher'      => [\App\Models\Accounts\Voucher::class,      ['number'],                     'Voucher',        'Accounts'],
        'acc_bank_account' => [\App\Models\Accounts\BankAccount::class,  ['bank_name', 'account_no'],    'Bank Account',   'Accounts'],
        'acc_cheque'       => [\App\Models\Accounts\Cheque::class,       ['reference'],                  'Cheque',         'Accounts'],
        'acc_budget'       => [\App\Models\Accounts\Budget::class,       ['name'],                       'Budget',         'Accounts'],
        'acc_group'        => [\App\Models\Accounts\AccountGroup::class, ['name'],                       'Account Group',  'Accounts'],

        // ── Purchase ────────────────────────────────────────────────────────
        'purchase_vendor'     => [\App\Models\Purchase\PurchaseVendor::class,      ['company_name'],                 'Purchase Vendor',   'Purchase'],
        'purchase_order'      => [\App\Models\Purchase\PurchaseOrder::class,       ['po_number', 'title'],           'Purchase Order',    'Purchase'],
        'purchase_invoice'    => [\App\Models\Purchase\PurchaseInvoice::class,     ['invoice_number', 'title'],      'Purchase Invoice',  'Purchase'],
        'purchase_request'    => [\App\Models\Purchase\PurchaseRequest::class,     ['pr_number', 'title'],           'Purchase Request',  'Purchase'],
        'purchase_rfq'        => [\App\Models\Purchase\PurchaseRfq::class,         ['rfq_number', 'title'],          'RFQ',               'Purchase'],
        'purchase_quotation'  => [\App\Models\Purchase\PurchaseQuotation::class,   ['quotation_number'],             'Quotation',         'Purchase'],
        'purchase_contract'   => [\App\Models\Purchase\PurchaseContract::class,    ['contract_number', 'title'],     'Purchase Contract', 'Purchase'],
        'purchase_debit_note' => [\App\Models\Purchase\PurchaseDebitNote::class,   ['debit_number'],                 'Debit Note',        'Purchase'],
        'goods_receipt'       => [\App\Models\Purchase\GoodsReceipt::class,        ['grn_number'],                   'Goods Receipt',     'Purchase'],
        'purchase_return'     => [\App\Models\Purchase\PurchaseOrderReturn::class, ['or_number'],                    'Order Return',      'Purchase'],
        'purchase_worker'     => [\App\Models\Purchase\PurchaseWorker::class,      ['worker_code', 'full_name'],     'Purchase Worker',   'Purchase'],
        'purchase_ncr'        => [\App\Models\Purchase\PurchaseNcr::class,         ['reference'],                    'Purchase NCR',      'Purchase'],
        'purchase_capa'       => [\App\Models\Purchase\PurchaseCapa::class,        ['reference'],                    'Purchase CAPA',     'Purchase'],
        'purchase_inspection' => [\App\Models\Purchase\PurchaseInspection::class,  ['reference'],                    'Inspection',        'Purchase'],
        'purchase_onboarding' => [\App\Models\Purchase\PurchaseOnboarding::class,  ['registration_number'],          'Onboarding',        'Purchase'],
        'purchase_package'    => [\App\Models\Purchase\PurchaseWorkPackage::class, ['reference'],                    'Work Package',      'Purchase'],
        'purchase_catalog'    => [\App\Models\Purchase\PurchaseCatalogItem::class, ['name'],                         'Catalog Item',      'Purchase'],
        'purchase_contact'    => [\App\Models\Purchase\PurchaseContact::class,     ['first_name', 'last_name'],      'Vendor Contact',    'Purchase'],

        // ── TPV / Vendors ───────────────────────────────────────────────────
        'vendor'          => [\App\Models\Vendor\Vendor::class,         ['company_name'],             'Third-Party Vendor', 'TPV'],
        'vendor_contact'  => [\App\Models\Vendor\VendorContact::class,  ['name'],                     'Vendor Contact',     'TPV'],
        'tpv_worker'      => [\App\Models\Tpv\TpvWorker::class,         ['worker_code', 'name'],      'TPV Worker',         'TPV'],
        'tpv_contract'    => [\App\Models\Tpv\TpvContract::class,       ['reference'],                'TPV Contract',       'TPV'],
        'tpv_ncr'         => [\App\Models\Tpv\TpvNcr::class,            ['reference'],                'TPV NCR',            'TPV'],
        'tpv_capa'        => [\App\Models\Tpv\TpvCapa::class,           ['reference'],                'TPV CAPA',           'TPV'],
        'tpv_inspection'  => [\App\Models\Tpv\TpvInspection::class,     ['reference'],                'TPV Inspection',     'TPV'],
        'tpv_onboarding'  => [\App\Models\Tpv\TpvOnboarding::class,     ['registration_number'],      'TPV Onboarding',     'TPV'],
        'tpv_work_order'  => [\App\Models\Tpv\TpvWorkOrder::class,      ['reference'],                'Work Order',         'TPV'],
        'tpv_package'     => [\App\Models\Tpv\TpvWorkPackage::class,    ['reference'],                'Work Package',       'TPV'],
        'tpv_contact'     => [\App\Models\Tpv\TpvContact::class,        ['first_name', 'last_name'],  'TPV Contact',        'TPV'],

        // ── Inventory ───────────────────────────────────────────────────────
        'inv_product'    => [\App\Models\Inventory\Product::class,       ['name'], 'Product',         'Inventory'],
        'inv_asset'      => [\App\Models\Inventory\Asset::class,         ['name'], 'Asset',           'Inventory'],
        'inv_warehouse'  => [\App\Models\Inventory\Warehouse::class,     ['name'], 'Warehouse',       'Inventory'],
        'inv_po'         => [\App\Models\Inventory\PurchaseOrder::class, ['code'], 'Inventory PO',    'Inventory'],
        'inv_transfer'   => [\App\Models\Inventory\Transfer::class,      ['code'], 'Stock Transfer',  'Inventory'],
        'inv_bom'        => [\App\Models\Inventory\Bom::class,           ['name'], 'Bill of Materials', 'Inventory'],
        'inv_build'      => [\App\Models\Inventory\BuildOrder::class,    ['code'], 'Build Order',     'Inventory'],
        'inv_picklist'   => [\App\Models\Inventory\PickList::class,      ['code'], 'Pick List',       'Inventory'],
        'inv_rental'     => [\App\Models\Inventory\Rental::class,        ['code'], 'Rental',          'Inventory'],
        'inv_vendor'     => [\App\Models\Inventory\Vendor::class,        ['name'], 'Inventory Vendor', 'Inventory'],
        'inv_vmi'        => [\App\Models\Inventory\VmiAgreement::class,  ['name'], 'VMI Agreement',   'Inventory'],
        'inv_count'      => [\App\Models\Inventory\CountSession::class,  ['name'], 'Count Session',   'Inventory'],

        // ── Work ────────────────────────────────────────────────────────────
        'project'         => [\App\Models\Project\Project::class,        ['name'],  'Project',         'Work'],
        'project_expense' => [\App\Models\Project\ProjectExpense::class, ['title'], 'Project Expense', 'Work'],
        'task'            => [\App\Models\Task\Task::class,              ['name'],  'Task',            'Work'],
        'ticket'          => [\App\Models\Helpdesk\Ticket::class,        ['subject'], 'Ticket',        'Work'],
        'meeting'         => [\App\Models\Shared\KickoffMeeting::class,  ['reference', 'title'], 'Meeting', 'Work'],
        'poll'            => [\App\Models\Shared\Poll::class,            ['question'], 'Poll',          'Work'],

        // ── People & compliance ─────────────────────────────────────────────
        'hr_advance'       => [\App\Models\Hr\HrAdvance::class,                  ['reference'], 'Salary Advance',      'HR'],
        'hr_reimbursement' => [\App\Models\Hr\HrReimbursement::class,            ['title'],     'Reimbursement',       'HR'],
        'comp_template'    => [\App\Models\Compliance\ComplianceTemplate::class, ['name'],      'Compliance Template', 'Compliance'],
        'comp_checklist'   => [\App\Models\Compliance\ComplianceChecklist::class, ['reference'], 'Compliance Checklist', 'Compliance'],
    ];

    /** @return array<string, array{0:class-string<Model>,1:array<int,string>,2:string,3:string}> */
    public static function all(): array
    {
        return self::TYPES;
    }

    public static function has(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    /** @return array{model:class-string<Model>, labels:array<int,string>, label:string, group:string} */
    public static function get(string $type): array
    {
        [$model, $labels, $label, $group] = self::TYPES[$type];

        return ['model' => $model, 'labels' => $labels, 'label' => $label, 'group' => $group];
    }

    /** The groups, in registry order — the order the UI shows its filters in. */
    public static function groups(): array
    {
        $seen = [];
        foreach (self::TYPES as [, , , $group]) {
            $seen[$group] = true;
        }

        return array_keys($seen);
    }

    /**
     * How a deleted row is named on screen.
     *
     * Several rows are only identifiable by two columns together — a contact is
     * a first and last name, a purchase order is a number AND what it was for.
     * Blank parts are dropped rather than leaving "· " hanging, and a row whose
     * label columns are all empty falls back to its id so it is still
     * restorable instead of being an unlabelled blank line.
     */
    public static function labelFor(Model $row, array $columns): string
    {
        $parts = [];
        foreach ($columns as $col) {
            $value = trim((string) ($row->{$col} ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return $parts === [] ? '#'.$row->getKey() : implode(' · ', $parts);
    }
}
