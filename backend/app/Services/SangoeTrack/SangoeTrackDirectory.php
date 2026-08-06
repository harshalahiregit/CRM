<?php

namespace App\Services\SangoeTrack;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads the SangoeTrack employee roster straight from its own MySQL database.
 *
 * Replaces the HTTP employee-listing endpoint, whose real route was never
 * available to us. A direct read removes that unknown entirely: the schema is
 * known, there is no pagination to get wrong, and no token to expire mid-run.
 *
 * READ ONLY by construction — this class issues SELECTs and nothing else, and
 * the connection is outside the migration path so `migrate` can never touch it.
 * Attendance and leave still go through SangoeTrackClient over HTTP.
 */
class SangoeTrackDirectory
{
    /** @var array<string, array<int, string>> resolved lookup tables, per kind */
    private array $lookups = [];

    public function isConfigured(): bool
    {
        return (bool) config('database.connections.'.$this->connectionName());
    }

    public function connectionName(): string
    {
        return (string) config('sangoetrack.db.connection', 'sangoetrack');
    }

    /**
     * Active employees in a workspace.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws SangoeTrackException when the connection or table is unusable
     */
    public function employees(?int $workspaceId = null): array
    {
        $workspace = $workspaceId ?? (int) config('sangoetrack.db.workspace', 1);
        $table     = (string) config('sangoetrack.db.table', 'employees');

        try {
            $rows = DB::connection($this->connectionName())
                ->table($table)
                ->where('workspace', $workspace)
                ->where('is_active', 1)
                ->orderBy('id')
                ->get();
        } catch (Throwable $e) {
            throw new SangoeTrackException(
                'Could not read SangoeTrack employees: '.$e->getMessage()
                .' — check SANGOETRACK_DB_* settings (password comes from SANGOETRACK_DB_PASSWORD).'
            );
        }

        return $rows->map(fn ($r) => (array) $r)->all();
    }

    /** Row count without pulling the rows — used by the dry-run summary. */
    public function count(?int $workspaceId = null): int
    {
        $workspace = $workspaceId ?? (int) config('sangoetrack.db.workspace', 1);

        try {
            return (int) DB::connection($this->connectionName())
                ->table((string) config('sangoetrack.db.table', 'employees'))
                ->where('workspace', $workspace)
                ->where('is_active', 1)
                ->count();
        } catch (Throwable $e) {
            throw new SangoeTrackException('Could not count SangoeTrack employees: '.$e->getMessage());
        }
    }

    /**
     * Resolve a foreign key to its label (department_id -> "Engineering").
     *
     * A missing lookup table is deliberately not fatal: SangoeTrack installs
     * vary, and an unresolvable department should degrade to the configured
     * default rather than abort a 28-employee import.
     */
    public function label(string $kind, mixed $id): ?string
    {
        if ($id === null || $id === '' || (int) $id <= 0) {
            return null;
        }

        if (! isset($this->lookups[$kind])) {
            $this->lookups[$kind] = $this->loadLookup($kind);
        }

        return $this->lookups[$kind][(int) $id] ?? null;
    }

    /** @return array<int, string> */
    private function loadLookup(string $kind): array
    {
        $cfg = (array) config('sangoetrack.db.lookups.'.$kind);

        if (! $cfg) {
            return [];
        }

        try {
            return DB::connection($this->connectionName())
                ->table($cfg['table'])
                ->pluck($cfg['label'], $cfg['key'])
                ->map(fn ($v) => (string) $v)
                ->all();
        } catch (Throwable $e) {
            Log::channel('hr')->info('SangoeTrack lookup table unavailable, using defaults', [
                'kind' => $kind, 'table' => $cfg['table'] ?? '?', 'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
