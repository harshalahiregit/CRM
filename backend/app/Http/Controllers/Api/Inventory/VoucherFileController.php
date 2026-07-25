<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\Inventory\Voucher;
use App\Models\Inventory\VoucherFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Attachments on a stock document — delivery challan, test certificate,
 * inspection photo, proof of delivery.
 *
 * Files go to the private local disk and are never publicly reachable; every
 * download runs through here so it stays authenticated and tenant-scoped, the
 * same rule the Task module's attachments follow.
 */
class VoucherFileController extends Controller
{
    use ApiResponse;
    use GuardsInventoryAccess;

    public function index(Request $request, string $type, int $id)
    {
        $this->denyExternal($request);
        $this->assertVoucher($request, $type, $id);

        return $this->success(
            VoucherFile::forTenant($request->user()->tenant_id)
                ->where('voucher_id', $id)->with('uploader:id,name')->latest()->get(),
            'Attachments retrieved'
        );
    }

    public function store(Request $request, string $type, int $id)
    {
        // Attaching paperwork is part of doing the document, so it takes the
        // same bar as changing it rather than a looser one.
        $this->guardVoucherManage($request, $id, 'attach files to this document');
        $this->assertVoucher($request, $type, $id);

        $data = $request->validate([
            'files'   => 'required|array|max:10',
            'files.*' => 'file|max:10240',                         // 10 MB each
            'kind'    => ['nullable', Rule::in(VoucherFile::KINDS)],
        ]);

        $tenantId = $request->user()->tenant_id;
        $kind = $data['kind'] ?? 'document';
        $saved = [];

        foreach ($request->file('files') as $file) {
            $saved[] = VoucherFile::create([
                'tenant_id'   => $tenantId,
                'voucher_id'  => $id,
                'file_path'   => $file->store("inventory/vouchers/{$tenantId}/{$id}", 'local'),
                'file_name'   => $file->getClientOriginalName(),
                'file_size'   => $file->getSize(),
                'mime_type'   => $file->getClientMimeType(),
                'kind'        => $kind,
                'uploaded_by' => $request->user()->id,
            ]);
        }

        return $this->success($saved, count($saved) === 1 ? 'File attached' : 'Files attached', 201);
    }

    public function download(Request $request, string $type, int $id, int $file)
    {
        $this->denyExternal($request);
        $this->assertVoucher($request, $type, $id);

        $row = VoucherFile::forTenant($request->user()->tenant_id)
            ->where('voucher_id', $id)->findOrFail($file);

        abort_unless(Storage::disk('local')->exists($row->file_path), 404, 'File missing from storage.');

        return Storage::disk('local')->download($row->file_path, $row->file_name);
    }

    public function destroy(Request $request, string $type, int $id, int $file)
    {
        $this->guardVoucherManage($request, $id, 'remove files from this document');
        $this->assertVoucher($request, $type, $id);

        $row = VoucherFile::forTenant($request->user()->tenant_id)
            ->where('voucher_id', $id)->findOrFail($file);

        // Delete the row first: an orphaned file on disk is harmless, whereas a
        // row pointing at a file that is gone shows a broken download link.
        $path = $row->file_path;
        $row->delete();
        Storage::disk('local')->delete($path);

        return $this->success(null, 'File removed');
    }

    /** The document must exist, be this tenant's, and be of the route's type. */
    private function assertVoucher(Request $request, string $type, int $id): void
    {
        $found = Voucher::forTenant($request->user()->tenant_id)
            ->whereKey($id)->where('type', $type)->exists();

        abort_unless($found, 404, 'That document does not exist.');
    }
}
