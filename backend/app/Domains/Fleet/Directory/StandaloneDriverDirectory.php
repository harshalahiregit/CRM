<?php

namespace App\Domains\Fleet\Directory;

use App\Domains\Fleet\Contracts\DriverDirectory;
use Illuminate\Support\Facades\DB;

/**
 * Drivers when STOS runs as its own application.
 *
 * There is no customer directory to read in standalone mode, so `stos_drivers`
 * is the source. Identical row shape to the CRM adapter, which is the whole
 * point: the screens, the services and the allocation engine cannot tell which
 * one they are talking to.
 *
 * In an integrated deployment this table stays empty and this class is never
 * resolved.
 */
class StandaloneDriverDirectory implements DriverDirectory
{
    public function people(int $companyId, array $filters = []): array
    {
        $query = DB::table('stos_drivers')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at');

        if ($term = trim((string) ($filters['q'] ?? ''))) {
            $like = '%'.$term.'%';
            $query->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('phone', 'like', $like));
        }

        if (! empty($filters['drivers_only'])) {
            $query->where(function ($w) {
                foreach (config('stos.directory.driver_keywords', ['driver']) as $word) {
                    $w->orWhere(DB::raw('lower(designation)'), 'like', '%'.strtolower($word).'%');
                }
            });
        }

        return $query->orderBy('name')->limit(500)->get()->map(fn ($r) => $this->present($r))->all();
    }

    public function find(int $companyId, string $source, int $sourceId): ?array
    {
        if ($source !== 'stos') {
            return null;
        }

        $row = DB::table('stos_drivers')
            ->where('company_id', $companyId)->where('id', $sourceId)
            ->whereNull('deleted_at')->first();

        return $row ? $this->present($row) : null;
    }

    public function describe(): string
    {
        return 'Read from the STOS driver register (standalone mode).';
    }

    private function present($r): array
    {
        return [
            'source'        => 'stos',
            'source_id'     => (int) $r->id,
            'ref'           => 'stos:'.$r->id,
            'name'          => (string) $r->name,
            'phone'         => $r->phone ? (string) $r->phone : null,
            'designation'   => $r->designation ? (string) $r->designation : null,
            'employer'      => $r->employer_name ? (string) $r->employer_name : null,
            'employer_type' => null,
            'directory'     => 'STOS drivers',
        ];
    }
}
