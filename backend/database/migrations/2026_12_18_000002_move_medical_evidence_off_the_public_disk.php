<?php

use App\Support\Medical\MedicalEvidence;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Move medical evidence off the publicly-served disk.
 *
 * Doctor signatures and stamps, and the signature and camera photo taken at
 * every examination, were written to `storage/app/public` — which is served at
 * /storage/** with no authentication — under names built from `uniqid()`.
 * uniqid() is derived from the clock rather than from randomness, so those
 * names were enumerable rather than secret. A downloadable doctor's signature
 * is a forgeable certificate.
 *
 * The columns hold a path RELATIVE to a disk, so moving the bytes from
 * `storage/app/public/<path>` to `storage/app/<path>` leaves every stored value
 * correct as it stands. Nothing in the database is rewritten here; this is a
 * file move, which is also why it is safe to run twice.
 *
 * Only the medical prefixes move. Worker photographs and induction shots stay
 * where they are: a photograph is not a forgery tool in the way a signature is,
 * and dragging them along would mean touching two other modules for no gain.
 */
return new class extends Migration
{
    /** The folders that hold evidence, and nothing else. */
    private const PREFIXES = [
        'medical/doctors',            // doctor signature / stamp / photo
        'medical/general/signatures', // internal, client and visitor examinations
        'medical/general/photos',
        'workers/signatures',         // TPV examinations
        'workers/medical/photos',
        'purchase/workers/signatures', // Purchase examinations
        'purchase/workers/medical/photos',
    ];

    public function up(): void
    {
        $this->move('public', MedicalEvidence::DISK);
    }

    /**
     * Reversible, so a rollback leaves the app working rather than leaving the
     * files somewhere the previous code cannot find them.
     */
    public function down(): void
    {
        $this->move(MedicalEvidence::DISK, 'public');
    }

    private function move(string $from, string $to): void
    {
        $source = Storage::disk($from);
        $target = Storage::disk($to);
        $moved  = 0;

        foreach (self::PREFIXES as $prefix) {
            if (! $source->exists($prefix)) {
                continue;
            }

            foreach ($source->allFiles($prefix) as $path) {
                // Never overwrite: if it is already on the target this migration
                // has run before, and the target copy is the live one.
                if ($target->exists($path)) {
                    $source->delete($path);

                    continue;
                }

                $stream = $source->readStream($path);

                if ($stream === false || $stream === null) {
                    Log::warning('Medical evidence could not be read while moving disks', ['path' => $path]);

                    continue;
                }

                $target->writeStream($path, $stream);

                if (is_resource($stream)) {
                    fclose($stream);
                }

                $source->delete($path);
                $moved++;
            }
        }

        Log::info('Medical evidence moved between disks', ['from' => $from, 'to' => $to, 'files' => $moved]);
    }
};
