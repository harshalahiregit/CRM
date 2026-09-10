<?php

namespace Sire\Adapters\Defaults;

use Sire\Contracts\SireVersionProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The shipped version provider: config first, then versions SIRE has seen.
 *
 * detected_version has to be automatic. Asking a user which build they were on
 * is asking them to go and look, and they will guess — so it comes from
 * config('sire.version'), set from APP_VERSION at deploy time, rather than from
 * anything the browser sends.
 *
 * Resolution order: config('sire.version'), then APP_VERSION, then null. A null
 * version is honest and costs nothing; a wrong one poisons every release
 * dashboard, which is why normalize() returns null rather than guessing at
 * anything that does not start with a number.
 *
 * known() is a picker list assembled from sire_releases, so it grows as releases
 * are recorded. A host with a real version registry implements the provider and
 * returns that instead.
 */
class SireLocalVersionProvider implements SireVersionProvider
{
    public function current(int $tenantId): ?string
    {
        return $this->normalize((string) (config('sire.version') ?? env('APP_VERSION') ?? ''));
    }

    public function environment(): ?string
    {
        return app()->environment();
    }

    public function known(int $tenantId): array
    {
        $current = $this->current($tenantId);

        if (! Schema::hasTable('sire_releases')) {
            return array_values(array_filter([$current]));
        }

        try {
            $versions = DB::table('sire_releases')
                ->where('tenant_id', $tenantId)
                ->whereNotNull('version')
                ->orderByDesc('id')
                ->limit(50)
                ->pluck('version')
                ->map(fn ($v) => $this->normalize($v))
                ->filter()
                ->unique()
                ->values()
                ->all();
        } catch (\Throwable $e) {
            report($e);
            $versions = [];
        }

        if ($current !== null && ! in_array($current, $versions, true)) {
            array_unshift($versions, $current);
        }

        return $versions;
    }

    public function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $trimmed = ltrim(trim($raw), 'vV');

        // Anything not starting with a number is not a version. Returning it
        // anyway would put "unknown" and "dev" into version dashboards as though
        // they were builds.
        if ($trimmed === '' || ! preg_match('/^\d+(\.\d+)*/', $trimmed, $matches)) {
            return null;
        }

        return $matches[0];
    }
}
