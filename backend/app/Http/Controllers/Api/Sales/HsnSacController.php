<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\Sales\HsnSacCode;
use Illuminate\Http\Request;

class HsnSacController extends Controller
{
    /**
     * GET /api/sales/hsn-sac?search=
     */
    public function search(Request $request)
    {
        $query = HsnSacCode::query();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return response()->json($query->orderBy('code')->limit(20)->get());
    }
}
