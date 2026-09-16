<?php

namespace Sire\Console\Commands;

use Sire\Support\SireStatus;
use Sire\Support\SireWorkflow;
use Illuminate\Console\Command;

/**
 * Writes frontend/src/lib/sire/workflow.generated.js from the PHP definition.
 *
 * The SPA needs labels, colours and ordering without a round-trip, and the only
 * safe way to have the machine in two languages is to have it authored in one.
 * Run this after every change to SireWorkflow; tests/workflow-parity.test.mjs
 * fails the build if the checked-in file is stale.
 */
class ExportSireWorkflow extends Command
{
    protected $signature = 'sire:export-workflow {--path=} {--check : Exit non-zero if the file is stale, write nothing}';

    protected $description = 'Export the SIRE workflow definition to the frontend as JSON';

    public function handle(): int
    {
        $path = $this->option('path')
            ?: base_path('../frontend/src/lib/sire/workflow.generated.js');

        $payload = [
            'version'      => 1,
            'generated_by' => 'php artisan sire:export-workflow',
            'warning'      => 'GENERATED FILE — do not edit. PHP (Sire\\Support\\SireWorkflow) is the authority; this mirror exists so the SPA can label and colour states without a round-trip. CI fails if it drifts.',
            'initial'      => SireWorkflow::INITIAL,
            'states'       => SireWorkflow::STATES,
            'sla_paused_states' => SireStatus::SLA_PAUSED,
            'transitions'  => collect(SireWorkflow::TRANSITIONS)
                ->map(fn (array $t, string $action) => array_merge(['action' => $action], $t))
                ->values()
                ->all(),
            'actions_without_transition' => collect(SireWorkflow::ACTIONS)
                ->map(fn (array $a, string $action) => array_merge(['action' => $action], $a))
                ->values()
                ->all(),
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // An ES module, not .json: a bare JSON import works in Vite but throws
        // ERR_IMPORT_ATTRIBUTE_MISSING in Node, so the tests could not load the
        // same file the app loads.
        $contents = "/* eslint-disable */\n"
            ."// GENERATED FILE — do not edit. Written by `php artisan sire:export-workflow`.\n"
            ."export default {$json};\n";

        if ($this->option('check')) {
            // Structural, not byte-for-byte. Whitespace and key order are not
            // drift, and a CI failure over indentation trains people to ignore it.
            $current = $this->decodeModule(is_file($path) ? (string) file_get_contents($path) : '');
            if ($this->normalise($current) !== $this->normalise($payload)) {
                $this->error("workflow.generated.js is stale. Run: php artisan sire:export-workflow");

                return self::FAILURE;
            }
            $this->info('workflow.generated.js is current.');

            return self::SUCCESS;
        }

        if (! is_dir(dirname($path))) {
            $this->error('Target directory does not exist: '.dirname($path));

            return self::FAILURE;
        }

        file_put_contents($path, $contents);
        $this->info('Wrote '.$path);

        return self::SUCCESS;
    }

    /** Pull the object literal back out of the generated ES module. */
    private function decodeModule(string $contents): mixed
    {
        if (! preg_match('/export default ([\s\S]*);\s*$/', trim($contents), $m)) {
            return null;
        }

        return json_decode($m[1], true);
    }

    /** Sort keys recursively so key order never counts as a difference. */
    private function normalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $out = array_map(fn ($v) => $this->normalise($v), $value);

        if (! array_is_list($out)) {
            ksort($out);
        }

        return $out;
    }
}
