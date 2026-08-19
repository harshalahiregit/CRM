<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Daily database backup (enhancement #12).
 *
 * Dumps the configured database, compresses it, writes it to the backup disk
 * (config/backup.php → BACKUP_DISK — local by default, or S3 / an OneDrive/
 * pCloud-mounted disk in production), and prunes to the last N archives so the
 * store never grows without bound. Driver-aware: SQLite is snapshotted by file
 * copy, MySQL via mysqldump, PostgreSQL via pg_dump.
 *
 * Safe to run repeatedly; every run produces one timestamped archive and deletes
 * anything beyond the retention count.
 */
class RunBackup extends Command
{
    protected $signature = 'backup:run {--keep= : Override how many archives to retain}';

    protected $description = 'Back up the database to the backup disk and prune old archives (enhancement #12)';

    public function handle(): int
    {
        $disk = config('backup.disk', 'backups');
        $path = trim((string) config('backup.path', 'db'), '/');
        $keep = (int) ($this->option('keep') ?: config('backup.keep', 4));
        $gzip = (bool) config('backup.gzip', true) && function_exists('gzopen');

        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");

        $this->info("Backing up [{$connection}] ({$driver}) → disk [{$disk}], keeping {$keep}.");

        // 1. Produce a local dump file.
        try {
            [$dumpPath, $ext] = $this->dump($connection, $driver);
        } catch (\Throwable $e) {
            $this->error('Backup failed: '.$e->getMessage());
            Log::error('Database backup failed', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        // 2. Compress + store on the destination disk.
        $stamp    = now()->format('Y-m-d_His');
        $name     = "{$path}/backup-{$connection}-{$stamp}.{$ext}".($gzip ? '.gz' : '');
        $stored   = $this->storeToDisk($disk, $name, $dumpPath, $gzip);
        @unlink($dumpPath);

        if (! $stored) {
            $this->error("Could not write the backup to disk [{$disk}].");

            return self::FAILURE;
        }

        $bytes = Storage::disk($disk)->size($name);
        $this->info('Wrote '.$name.' ('.$this->human($bytes).').');

        // 3. Prune to the retention count.
        $deleted = $this->prune($disk, $path, $keep);
        if ($deleted) {
            $this->line("Pruned {$deleted} old archive(s).");
        }

        Log::channel('daily')->info('Database backup completed', [
            'disk' => $disk, 'file' => $name, 'bytes' => $bytes, 'pruned' => $deleted,
        ]);

        return self::SUCCESS;
    }

    /**
     * Create a local dump file and return [absolutePath, extension].
     */
    private function dump(string $connection, ?string $driver): array
    {
        $cfg = config("database.connections.{$connection}");
        $tmp = tempnam(sys_get_temp_dir(), 'crmbak_');

        switch ($driver) {
            case 'sqlite':
                $db = $cfg['database'] ?? null;
                if (! $db || ! is_file($db)) {
                    throw new \RuntimeException('SQLite database file not found.');
                }
                if (! copy($db, $tmp)) {
                    throw new \RuntimeException('Could not copy the SQLite database file.');
                }

                return [$tmp, 'sqlite'];

            case 'mysql':
            case 'mariadb':
                $this->runDumpProcess($this->mysqlDumpCommand($cfg), $tmp, $this->mysqlEnv($cfg));

                return [$tmp, 'sql'];

            case 'pgsql':
                $this->runDumpProcess($this->pgsqlDumpCommand($cfg), $tmp, ['PGPASSWORD' => (string) ($cfg['password'] ?? '')]);

                return [$tmp, 'sql'];

            default:
                @unlink($tmp);
                throw new \RuntimeException("Unsupported database driver for backup: {$driver}");
        }
    }

    /** Run a dump command, streaming stdout into $outFile. */
    private function runDumpProcess(array $command, string $outFile, array $env = []): void
    {
        $out = fopen($outFile, 'wb');
        $process = new Process($command, base_path(), array_merge($_ENV, $env));
        $process->setTimeout(1800);   // 30 min for a large DB
        $process->run(function ($type, $buffer) use ($out) {
            if ($type === Process::OUT) {
                fwrite($out, $buffer);
            }
        });
        fclose($out);

        if (! $process->isSuccessful()) {
            @unlink($outFile);
            throw new \RuntimeException(trim($process->getErrorOutput()) ?: 'dump process failed');
        }
    }

    private function mysqlDumpCommand(array $cfg): array
    {
        return array_filter([
            'mysqldump',
            '--host='.($cfg['host'] ?? '127.0.0.1'),
            '--port='.($cfg['port'] ?? '3306'),
            '--user='.($cfg['username'] ?? 'root'),
            '--single-transaction', '--quick', '--no-tablespaces', '--skip-lock-tables',
            $cfg['database'] ?? '',
        ]);
    }

    /** Pass the password via env, never on the command line (avoids ps leakage). */
    private function mysqlEnv(array $cfg): array
    {
        return ['MYSQL_PWD' => (string) ($cfg['password'] ?? '')];
    }

    private function pgsqlDumpCommand(array $cfg): array
    {
        return array_filter([
            'pg_dump',
            '--host='.($cfg['host'] ?? '127.0.0.1'),
            '--port='.($cfg['port'] ?? '5432'),
            '--username='.($cfg['username'] ?? 'postgres'),
            '--no-owner', '--no-privileges',
            $cfg['database'] ?? '',
        ]);
    }

    /** Compress (optional) and copy the local dump onto the destination disk. */
    private function storeToDisk(string $disk, string $name, string $localPath, bool $gzip): bool
    {
        if ($gzip) {
            $gzPath = $localPath.'.gz';
            $in  = fopen($localPath, 'rb');
            $out = gzopen($gzPath, 'wb9');
            while (! feof($in)) {
                gzwrite($out, fread($in, 262144));
            }
            fclose($in);
            gzclose($out);

            $ok = Storage::disk($disk)->writeStream($name, fopen($gzPath, 'rb'));
            @unlink($gzPath);

            return $ok;
        }

        return Storage::disk($disk)->writeStream($name, fopen($localPath, 'rb'));
    }

    /** Keep the newest $keep archives under $path; delete the rest. */
    private function prune(string $disk, string $path, int $keep): int
    {
        if ($keep < 1) {
            return 0;
        }

        $files = collect(Storage::disk($disk)->files($path))
            ->filter(fn ($f) => str_contains(basename($f), 'backup-'))
            ->sortDesc()   // timestamped names sort chronologically
            ->values();

        $stale = $files->slice($keep);
        foreach ($stale as $f) {
            Storage::disk($disk)->delete($f);
        }

        return $stale->count();
    }

    private function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $n = (float) $bytes;
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }

        return round($n, 1).' '.$units[$i];
    }
}
