<?php

namespace App\Console\Commands\SangoeTrack;

use App\Services\SangoeTrack\SangoeTrackClient;
use App\Services\SangoeTrack\SangoeTrackException;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * Dumps a raw SangoeTrack response so config/sangoetrack.php `map` can be
 * confirmed against reality.
 *
 * The response shapes were never specified to us, so every field the sync reads
 * goes through an ordered candidate list. This command shows which candidate
 * actually matched — run it once per endpoint before trusting a sync.
 */
class ProbeSangoeTrack extends Command
{
    protected $signature = 'sangoetrack:probe
                            {endpoint=attendance_history : login|attendance_history|leaves|leave_types|leave_balance}
                            {--user= : SangoeTrack user id}
                            {--workspace= : Workspace id (defaults to SANGOETRACK_WORKSPACE_ID)}
                            {--month= : 1-12}
                            {--year= : e.g. 2026}
                            {--raw : Print the entire body instead of a trimmed sample}';

    protected $description = 'Dump a raw SangoeTrack response and report which configured field keys matched';

    public function handle(SangoeTrackClient $client): int
    {
        $endpoint = (string) $this->argument('endpoint');

        if (! config('sangoetrack.endpoints.'.$endpoint)) {
            $this->error('Unknown endpoint. One of: '.implode(', ', array_keys((array) config('sangoetrack.endpoints'))));

            return self::FAILURE;
        }

        if (! $client->isConfigured()) {
            $this->error('SangoeTrack is not configured. Set SANGOETRACK_BASE_URL, SANGOETRACK_EMAIL, SANGOETRACK_PASSWORD.');

            return self::FAILURE;
        }

        $payload = array_filter([
            'user_id'      => $this->option('user') ? (int) $this->option('user') : null,
            'workspace_id' => (int) ($this->option('workspace') ?: config('sangoetrack.workspace_id')) ?: null,
            'month'        => $this->option('month'),
            'year'         => $this->option('year'),
        ], fn ($v) => $v !== null);

        $this->line('POST '.config('sangoetrack.base_url').config('sangoetrack.endpoints.'.$endpoint));
        $this->line('payload: '.json_encode($payload));
        $this->newLine();

        try {
            $result = $endpoint === 'login'
                ? ['status' => 200, 'body' => ['token' => substr($client->login(true), 0, 12).'… (truncated)']]
                : $client->raw($endpoint, $payload);
        } catch (SangoeTrackException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('HTTP '.$result['status']);

        $body = $result['body'];

        if ($this->option('raw')) {
            $this->line(json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            // One sample row is enough to read the shape; whole months are noise.
            $rows = $this->locateRows($body);
            $this->line(json_encode(
                $rows === null ? $body : ['<rows>' => array_slice($rows, 0, 2), '<count>' => count($rows)],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ));

            if (is_array($rows) && $rows !== []) {
                $this->newLine();
                $this->info('Field mapping against the first row:');
                foreach (['date', 'check_in', 'check_out', 'status'] as $field) {
                    [$key, $value] = $this->match($rows[0], $field);
                    $this->line(sprintf(
                        '  %-10s %s',
                        $field,
                        $key ? "matched '{$key}' => ".json_encode($value) : 'NO MATCH — add the real key to config/sangoetrack.php map.'.$field
                    ));
                }
            }
        }

        return self::SUCCESS;
    }

    private function locateRows(array $body): ?array
    {
        foreach ((array) config('sangoetrack.map.rows', []) as $key) {
            $value = Arr::get($body, $key);
            if (is_array($value)) {
                return array_is_list($value) ? $value : [$value];
            }
        }

        return array_is_list($body) ? $body : null;
    }

    /** @return array{0:?string, 1:mixed} */
    private function match(array $row, string $field): array
    {
        foreach ((array) config('sangoetrack.map.'.$field, []) as $key) {
            $value = Arr::get($row, $key);
            if ($value !== null && $value !== '') {
                return [$key, $value];
            }
        }

        return [null, null];
    }
}
