<?php

namespace App\Casts;

use App\Support\Shared\BusinessTime;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A datetime column that holds a **wall clock in the business timezone**, not a
 * UTC instant.
 *
 * Meeting start and end times are what a person typed on a form: "2:30 PM on
 * the 4th". The column has always stored exactly that. What was missing was
 * anything that said so, so the plain `datetime` cast handed the value out as
 * though the 14:30 were UTC and every reader shifted it again.
 *
 * Reading, this attaches the business zone the value was always in. Writing, it
 * accepts either form — a bare wall clock (which is read as business time) or
 * an offset-bearing instant from a browser in any timezone (which is converted
 * into business time) — and stores the wall clock. So the value survives a
 * create/edit/re-save round trip unchanged instead of drifting a further offset
 * each time, and a viewer abroad sees the meeting at their own correct hour.
 *
 * The tenant supplies the zone, taken from the model's own `tenant_id`, so no
 * ambient tenant context is needed and a queued job or console command reads
 * the same zone as the request that wrote the row.
 *
 * Use it ONLY for person-entered times. Machine timestamps — created_at,
 * completed_at, anything set from `now()` — are genuinely UTC and correct as
 * they are; casting those this way would break them.
 */
class BusinessDateTime implements CastsAttributes
{
    /** @param  Model  $model */
    public function get($model, string $key, $value, array $attributes)
    {
        return BusinessTime::parse($value, $model->tenant_id ?? null);
    }

    /** @param  Model  $model */
    public function set($model, string $key, $value, array $attributes)
    {
        return [$key => BusinessTime::toStorage($value, $model->tenant_id ?? null)];
    }
}
