<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Shared tenant-ownership guard for API controllers that resolve a model via
 * route-model binding. A model belonging to another tenant is treated as
 * not-found (404), never as forbidden — we do not confirm its existence.
 *
 * Controllers that need a bespoke check (e.g. a nested owner) still define their
 * own private assertTenant instead of using this trait.
 */
trait AssertsTenantOwnership
{
    protected function assertTenant(Request $request, Model $model): void
    {
        abort_unless((int) $model->tenant_id === (int) $request->user()->tenant_id, 404);
    }
}
